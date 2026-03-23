<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Public phone/reference verification form for appointment management.
 */
final class AppointmentLookupForm extends AppointmentManagementBaseForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_lookup_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'appointment/booking_wizard';

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<h2>' . $this->t('Manage your appointment') . '</h2>'
        . '<p>' . $this->t('Enter your reference code and phone number to modify or cancel your appointment.') . '</p>',
    ];

    $form['wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['booking-wizard']],
    ];

    $form['wrapper']['reference'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Appointment reference'),
      '#description' => $this->t('Example: RDV-2026-01011230-A1B2 (check your confirmation email)'),
      '#required' => TRUE,
      '#maxlength' => 25,
      '#default_value' => (string) $form_state->getValue('reference'),
    ];

    $form['wrapper']['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone number'),
      '#required' => TRUE,
      '#default_value' => (string) $form_state->getValue('phone'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Validate'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $reference = strtoupper(trim((string) $form_state->getValue('reference')));
    $phone = $this->managementHelper->normalizePhone((string) $form_state->getValue('phone'));
    $identifier = (string) ($this->getRequest()->getClientIp() ?? 'unknown');

    $flood = \Drupal::service('flood');
    if (!$flood->isAllowed('appointment.lookup', 10, 3600, $identifier)) {
      $form_state->setErrorByName('reference', $this->t('Too many attempts. Please try again later.'));
      return;
    }

    if ($phone === '') {
      $form_state->setErrorByName('phone', $this->t('Please enter a valid phone number.'));
      $flood->register('appointment.lookup', 3600, $identifier);
      return;
    }

    $appointment = $this->managementHelper->loadByReference($reference);
    if (!$appointment instanceof ContentEntityInterface) {
      $form_state->setErrorByName('reference', $this->t('No appointment matches the provided details.'));
      $flood->register('appointment.lookup', 3600, $identifier);
      return;
    }

    $stored_phone = $this->managementHelper->normalizePhone((string) ($appointment->get('customer_phone')->value ?? ''));
    if ($stored_phone === '' || $stored_phone !== $phone) {
      $form_state->setErrorByName('phone', $this->t('No appointment matches the provided details.'));
      $flood->register('appointment.lookup', 3600, $identifier);
      return;
    }

    $status = (string) ($appointment->get('appointment_status')->value ?? 'pending');
    if ($status === 'cancelled') {
      $form_state->setErrorByName('reference', $this->t('This appointment is already cancelled.'));
      $flood->register('appointment.lookup', 3600, $identifier);
      return;
    }

    $form_state->set('verified_appointment_id', (int) $appointment->id());
    $form_state->set('verified_phone', $phone);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $appointment_id = (int) ($form_state->get('verified_appointment_id') ?? 0);
    $phone = (string) ($form_state->get('verified_phone') ?? '');

    $appointment = $this->entityTypeManager->getStorage('appointment')->load($appointment_id);
    if (!$appointment instanceof ContentEntityInterface) {
      $this->messenger()->addError($this->t('The appointment could not be loaded. Please try again.'));
      return;
    }

    $this->managementHelper->setVerifiedAppointment($appointment, $phone);
    $this->messenger()->addStatus($this->t('Appointment found. You can now modify or cancel it.'));
    $form_state->setRedirect('appointment.manage_actions');
  }

}
