<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Public cancellation form for a verified appointment.
 */
final class AppointmentCancelForm extends AppointmentManagementBaseForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_cancel_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'appointment/booking_wizard';

    $appointment = $this->managementHelper->getVerifiedAppointment();
    if (!$appointment) {
      $form['expired'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Your verification session has expired. Please verify again.') . '</p>',
      ];
      return $form;
    }

    $form['header'] = [
      '#type' => 'markup',
      '#markup' => '<h2>' . $this->t('Cancel appointment') . '</h2>'
        . '<p>' . $this->t('This action will cancel your appointment and free the slot.') . '</p>',
    ];

    $form['summary'] = [
      '#type' => 'markup',
      '#markup' => $this->managementHelper->buildAppointmentSummaryMarkup($appointment),
    ];

    $status = (string) ($appointment->get('appointment_status')->value ?? 'pending');
    if ($status === 'cancelled') {
      $form['already_cancelled'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('This appointment is already cancelled.') . '</p>',
      ];
      return $form;
    }

    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I confirm that I want to cancel this appointment.'),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel appointment'),
      '#button_type' => 'danger',
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::backSubmit'],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Returns to action selection.
   */
  public function backSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('appointment.manage_actions');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $appointment = $this->managementHelper->getVerifiedAppointment();
    if (!$appointment) {
      $this->messenger()->addError($this->t('Your verification session has expired.'));
      $form_state->setRedirect('appointment.manage_lookup');
      return;
    }

    if ((string) ($appointment->get('appointment_status')->value ?? '') !== 'cancelled') {
      $appointment->set('appointment_status', 'cancelled');
      $appointment->set('status', FALSE);
      $appointment->save();
      $this->managementHelper->sendAppointmentMail('cancelled', $appointment);
    }

    $this->managementHelper->clearVerification();
    $this->messenger()->addStatus($this->t('Your appointment has been cancelled.'));
    $form_state->setRedirect('appointment.manage_lookup');
  }

}
