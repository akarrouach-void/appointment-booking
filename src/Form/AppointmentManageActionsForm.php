<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Public action chooser for a verified appointment.
 */
final class AppointmentManageActionsForm extends AppointmentManagementBaseForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_manage_actions_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'appointment/booking_wizard';

    $appointment = $this->getVerifiedAppointment();
    if (!$appointment) {
      $form['expired'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Your verification session has expired. Please verify again.') . '</p>',
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['back_to_lookup'] = [
        '#type' => 'link',
        '#title' => $this->t('Verify appointment'),
        '#url' => \Drupal\Core\Url::fromRoute('appointment.manage_lookup'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
      return $form;
    }

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<h2>' . $this->t('Appointment confirmed') . '</h2>',
    ];

    $form['summary'] = [
      '#type' => 'markup',
      '#markup' => $this->buildAppointmentSummaryMarkup($appointment),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['modify'] = [
      '#type' => 'submit',
      '#value' => $this->t('Modify appointment'),
      '#submit' => ['::modifySubmit'],
      '#limit_validation_errors' => [],
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel appointment'),
      '#submit' => ['::cancelSubmit'],
      '#limit_validation_errors' => [],
      '#button_type' => 'danger',
    ];

    $form['actions']['restart'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify another appointment'),
      '#submit' => ['::restartSubmit'],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Goes to the modify form.
   */
  public function modifySubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('appointment.manage_modify');
  }

  /**
   * Goes to the cancel form.
   */
  public function cancelSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('appointment.manage_cancel');
  }

  /**
   * Restarts appointment verification.
   */
  public function restartSubmit(array &$form, FormStateInterface $form_state): void {
    $this->clearVerification();
    $form_state->setRedirect('appointment.manage_lookup');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
