<?php
/**
 * @file
 * Contains \Drupal\r25\Plugin\QueueWorker\R25SyncQueue.
 */
namespace Drupal\r25sync\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Component\Utility\Xss;

/**
 * Processes Tasks for Learning.
 *
 * @QueueWorker(
 *   id = "r25sync_queue",
 *   title = @Translation("R25SyncQueue"),
 *   cron = {"time" = 60}
 * )
 */
class R25SyncQueue extends QueueWorkerBase {
	/**
	* {@inheritdoc}
	*/
	public function processItem($data) {
		switch($data['op']) {
			case 'fetch':
				$this->fetchData($data);
				break;
			case 'map':
				$this->processEvent($data);
				break;
			case 'eval':
				$this->processEval($data);
				break;
			default:
				break;
		}
	}
	
	public function fetchData($data) {
		\Drupal::logger('r25sync')->notice("Fetching data for date offset " . $data['start_dt'] . ".");
		
		/* check configuration */
		$config = \Drupal::config('r25sync.configuration');
		
		if ($config->get('organization_id') == null ||
			$config->get('space_query_id') == null ||
			$config->get('username') == null ||
			$config->get('password') == null) return false;
		
		/* get resource URL */
		$url = "https://25live.collegenet.com/25live/data/" . $config->get('organization_id') . "/run/rm_rsrvs.xml?space_query_id=" . $config->get('space_query_id') . "&start_dt=" . $data['start_dt'];

		/*if (($end_dt = $config->get('end_dt')) != null)
			if ($end_dt != 0)
				$url .= "&start_dt=0&end_dt=+" . $end_dt;*/
		
		/* setup curl */
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERPWD, $config->get('username') . ":" . $config->get('password'));
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		
		$data = curl_exec($ch);
		$reservations = array();
		
		if ($error = curl_error($ch)) {
			\Drupal::logger('r25sync')->error("[R25Sync Error] " . $error);	/* log error */	
		} else {
			/* parse xml */
			$xml = simplexml_load_string($data);
			if ($xml == false) {
				$i = 0;
				$message = "";
				foreach(libxml_get_errors() as $error) {
					if (strlen($message)) $message .= ", ";
					$message .= "(" . $i . ") " . $error->message;
				}
				\Drupal::logger('r25sync')->error("[R25Sync Error] " . $message);
			} else {
				$queue = \Drupal::queue('r25sync_queue');
				/* based on https://github.com/mmardosz/print25live/blob/master/parser.php */
				/* note: code assumes space, organization and event_type precede space_reservation atoms. */
				$space = array();
				$organization = array();
				$event_type = array();
				foreach ($xml->children('r25', true) as $child) {
					switch(Xss::filter($child->getName())) {
						case 'space':
							$space[Xss::filter((string)$child->space_id_ref)] = Xss::filter((string)$child->space_name);
							break;
						case 'organization':
							$organization[Xss::filter((string)$child->organization_id_ref)] = Xss::filter((string)$child->organization_name);
							break;
						case 'event_type':
							$event_type[Xss::filter((string)$child->event_type_id_ref)] = Xss::filter((string)$child->event_type_name);
							break;
						case 'space_reservation':
							$event_start_dt = Xss::filter((string)$child->event_start_dt);
							$event_end_dt = Xss::filter((string)$child->event_end_dt);
						
							$data = array(
								'op' => 'map',
								'event_id' => Xss::filter((string)$child->event_id),
								'name' => Xss::filter((string)$child->event_name),
								'space' => $space[Xss::filter((string)$child->space_id)],
								'event_start_dt' =>date("Y-m-d\TH:i:s",  strtotime("-" . substr($event_start_dt, -5, 2) . " hours", strtotime($event_start_dt))),
								'event_end_dt' => date("Y-m-d\TH:i:s", strtotime("-" . substr($event_end_dt, -5, 2) . " hours", strtotime($event_end_dt))),
								'organization' =>$organization[Xss::filter((string)$child->organization_id)],
							);
							
							$queue->createItem($data);
							break;
						default:
					}
				}
			}
		}
		
		curl_close($ch);
	}
	
	public function processEvent($data) {
		/* evaluate name */
		$config = \Drupal::config('r25sync.configuration');
		$exp = $config->get('regex');
		if (preg_match($exp, $data['name'])) return;
		
		$query = \Drupal::entityQuery('node')
					->condition('type', 'r25_event')
					->condition('field_r25_event_id', $data['event_id'], '=')
					->range(0, 1);
		
		$result = $query->execute();
		if (count($result)) {
			/* update existing node */
			$nid = array_values($result)[0];
			
			$node = \Drupal\node\Entity\Node::load($nid);
			
			$node->changed = REQUEST_TIME;
			$node->title = $data['name'];
			$node->field_r25_start_date = $data['event_start_dt'];
			$node->field_r25_end_date = $data['event_end_dt'];
			$node->field_r25_organization = $data['organization'];
			$node->field_r25_space = $data['space'];
			
			$node->save();
		} else {
			$node = \Drupal\node\Entity\Node::create([
				'type' => 'r25_event',
				'langcode' => 'en',
				'created' => REQUEST_TIME,
				'changed' => REQUEST_TIME,
				'uid' => 1,
				'title' => $data['name'],
				'field_r25_event_id' => $data['event_id'],
				'field_r25_start_date' => $data['event_start_dt'],
				'field_r25_end_date' => $data['event_end_dt'],
				'field_r25_organization' => $data['organization'],
				'field_r25_space' => $data['space'],
			]);
			
			$node->setPromoted(false);
			
			$node->save();
		}
	}
	
	function processEval($data) {
		$node = node_load($data['nid']);
		$config = \Drupal::config('r25sync.configuration');
		$exp = $config->get('regex');
		
		if(preg_match($exp, $node->getTitle())) {
			\Drupal::logger('r25sync')->notice("Remove R25Event " . $node->getTitle() . " (" . $node->id() . ").");
			$node->delete();
		}
	}
}