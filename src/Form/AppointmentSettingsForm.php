<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for the appointment module.
 */
final class AppointmentSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['appointment.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('appointment.settings');

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Settings'),
      '#open' => TRUE,
    ];

    $form['notifications'] = [
      '#type' => 'details',
      '#title' => $this->t('Email Notifications'),
      '#open' => TRUE,
      '#description' => $this->t('Available replacement variables: @reference, @date, @agency, @type, @adviser.'),
    ];

    // Notification: Created
    $form['notifications']['email_created'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Appointment Created'),
    ];
    $form['notifications']['email_created']['email_subject_created'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $config->get('email_subject_created') ?? 'Your appointment @reference has been created',
      '#required' => TRUE,
    ];
    $form['notifications']['email_created']['email_body_created'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('email_body_created') ?? 'Your appointment has been created successfully. Keep your reference to modify or cancel it later.',
      '#required' => TRUE,
    ];

    // Notification: Modified
    $form['notifications']['email_modified'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Appointment Modified'),
    ];
    $form['notifications']['email_modified']['email_subject_modified'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $config->get('email_subject_modified') ?? 'Your appointment @reference has been modified',
      '#required' => TRUE,
    ];
    $form['notifications']['email_modified']['email_body_modified'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('email_body_modified') ?? 'Your appointment has been updated successfully.',
      '#required' => TRUE,
    ];

    // Notification: Cancelled
    $form['notifications']['email_cancelled'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Appointment Cancelled'),
    ];
    $form['notifications']['email_cancelled']['email_subject_cancelled'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $config->get('email_subject_cancelled') ?? 'Your appointment @reference has been cancelled',
      '#required' => TRUE,
    ];
    $form['notifications']['email_cancelled']['email_body_cancelled'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('email_body_cancelled') ?? 'Your appointment has been cancelled successfully.',
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('appointment.settings')
      ->set('email_subject_created', (string) $form_state->getValue('email_subject_created'))
      ->set('email_body_created', (string) $form_state->getValue('email_body_created'))
      ->set('email_subject_modified', (string) $form_state->getValue('email_subject_modified'))
      ->set('email_body_modified', (string) $form_state->getValue('email_body_modified'))
      ->set('email_subject_cancelled', (string) $form_state->getValue('email_subject_cancelled'))
      ->set('email_body_cancelled', (string) $form_state->getValue('email_body_cancelled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
