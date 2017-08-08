<?php

/* R25ConfigurationForm.php, v. RC2, bamberj, 08.08.2017 */

/**
 * @file
 * Contains \Drupal\r25sync\Form\R25ConfigurationForm.
 */

namespace Drupal\r25sync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;


/**
 * Contribute form.
 */
class R25ConfigurationForm extends FormBase {
	/**
	* {@inheritdoc}
	*/
	public function getFormId() {
		return 'r25_configuration_form';
	}

	/**
	* {@inheritdoc}
	*/
	public function buildForm(array $form, FormStateInterface $form_state) {
		$config = \Drupal::config('r25sync.configuration');
		$queue = \Drupal::queue('r25sync_queue');
		
		$form['help'] = array(
		  '#type' => 'markup',
		  '#markup' => $this->t('There are @number items remaing in the proccessing queue.', array('@number' => $queue->numberOfItems())),
		);
		
		$form['connection_details'] = array(
		  '#type' => 'fieldset',
		  '#title' => $this->t('Connection Details'),
		);
		$form['connection_details']['organization_id'] = array(
			'#type' => 'textfield',
			'#title' => $this->t('Organization ID'),
			'#default_value' => $config->get('organization_id'),
			'#size' => 32,
		);
		$form['connection_details']['space_query_id'] = array(
			'#type' => 'textfield',
			'#title' => $this->t('Space Query ID'),
			'#default_value' => $config->get('space_query_id'),
			'#size' => 32,
		);
		$duration_increments = array(30, 60, 90, 120);
		$form['connection_details']['end_dt'] = array(
			'#type' => 'select',
			'#title' => $this->t('End Date'),
			'#description' => $this->t('Obtain reservations that occur on or before this date (number of days relative to today).'),
			'#options' => array_combine($duration_increments, $duration_increments),
			'#default_value' => $config->get('end_dt'),
		);
		$form['account_credentials'] = array(
		  '#type' => 'fieldset',
		  '#title' => $this->t('Account Credentials'),
		);
		$form['account_credentials']['username'] = array(
			'#type' => 'textfield',
			'#title' => $this->t('Username'),
			'#default_value' => $config->get('username'),
			'#size' => 32,
		);
		$form['account_credentials']['password'] = array(
			'#type' => 'password',
			'#title' => $this->t('Password'),
			'#size' => 32,
		);
		$form['submit'] = array(
			'#type' => 'submit',
			'#value' => $this->t('Save Configuration'),
		);
		/*$form['refresh'] = array(
			'#type' => 'submit',
			'#value' => $this->t('Refresh Data'),
		);*/
		
		return $form;
	}

	/**
	* {@inheritdoc}
	*/
	public function validateForm(array &$form, FormStateInterface $form_state) {
		
	}

	/**
	* {@inheritdoc}
	*/
	public function submitForm(array &$form, FormStateInterface $form_state) {
		$values = $form_state->getValues();
		
		switch($values['op']) {
			case (string)$this->t('Save Configuration'):
				$config = \Drupal::service('config.factory')->getEditable('r25sync.configuration');
				
				foreach ($values as $key => $value) {
					if ($key != "submit" &&
						$key != "refresh" &&
						$key != "form_build_id" &&
						$key != "form_token" &&
						$key != "form_id" &&
						$key != "op")
							if (!empty($value) || ($key == "end_dt"))	/* permit zero value for end_dt */
								$config->set($key, $value);
				}
				
				$config->save();
				break;
			/*case (string)$this->t('Refresh Data'):
				if (r25sync_update()) {
					drupal_set_message($this->t("Data refreshed."));
				} else {
					drupal_set_message($this->t("Data refresh failed. Please check configuration."));
				}
				break;*/
			default:
		}
	}
}
