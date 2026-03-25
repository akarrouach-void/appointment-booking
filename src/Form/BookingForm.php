<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Public multi-step booking form (new appointments).
 */
final class BookingForm extends BookingFormBase {

  private const STORE_KEY = 'wizard_data';

  public function getFormId(): string {
    return 'appointment_booking_form';
  }

  protected function storeKey(): string {
    return self::STORE_KEY;
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $step = $this->currentStep($form_state);
    $data = $this->loadWizardData();

    $this->initWizardScaffold($form, $step);

    match ($step) {
      1       => $this->buildStepAgency($form['wizard'], $data),
      2       => $this->buildStepType($form['wizard'], $data),
      3       => $this->buildStepAdviser($form['wizard'], $data),
      4       => $this->buildStepDate($form['wizard'], $data),
      5       => $this->buildStepCustomer($form['wizard'], $data),
      default => $this->buildStepConfirm($form['wizard'], $data),
    };

    $this->addActions($form, $step, (string) $this->t('Confirm appointment'));

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $data = $this->loadWizardData();

    // Guard: all required keys must be present.
    foreach (['agency', 'appointment_type', 'adviser', 'appointment_day', 'appointment_time', 'appointment_date', 'customer_name', 'customer_email', 'customer_phone'] as $key) {
      if (empty($data[$key])) {
        $this->messenger()->addError($this->t('Please complete all steps before saving.'));
        $form_state->set('step', 1);
        $form_state->setRebuild(TRUE);
        return;
      }
    }

    // Guard: slot still available (race-condition check).
    $slots = $this->wizardHelper->getAvailableHalfHourSlots((int) $data['adviser'], (string) $data['appointment_day']);
    if (!isset($slots[(string) $data['appointment_time']])) {
      $this->messenger()->addError($this->t('Selected slot is no longer available. Please choose another time.'));
      $form_state->set('step', 4);
      $form_state->setRebuild(TRUE);
      return;
    }

    /** @var \Drupal\appointment\Entity\AppointmentInterface $appointment */
    $appointment = $this->entityTypeManager->getStorage('appointment')->create([
      'label'               => 'Appointment ' . date('Y-m-d H:i'),
      'appointment_agency'  => (int) $data['agency'],
      'appointment_type'    => (int) $data['appointment_type'],
      'appointment_adviser' => (int) $data['adviser'],
      'appointment_date'    => $data['appointment_date'],
      'customer_name'       => $data['customer_name'],
      'customer_email'      => $data['customer_email'],
      'customer_phone'      => $data['customer_phone'],
      'notes'               => $data['notes'] ?? '',
      'appointment_status'  => 'pending',
      'status'              => TRUE,
    ]);
    $appointment->save();
    $this->wizardHelper->sendAppointmentMail('created', $appointment);

    $reference = (string) ($appointment->get('reference')->value ?? '');
    $this->clearWizardData();
    $form_state->set('step', 1);
    $this->messenger()->addStatus($this->t('Your appointment has been created. Reference: @reference', ['@reference' => $reference ?: '-']));
    $form_state->setRedirect('appointment.manage_lookup');
  }

  private function buildStepConfirm(array &$container, array $data): void {
    $container['title']['#markup'] = '<h3>6. ' . $this->t('Review and confirm') . '</h3>';

    $agency  = $this->entityTypeManager->getStorage('agency')->load((int) ($data['agency'] ?? 0));
    $type    = $this->entityTypeManager->getStorage('taxonomy_term')->load((int) ($data['appointment_type'] ?? 0));
    $adviser = $this->entityTypeManager->getStorage('user')->load((int) ($data['adviser'] ?? 0));

    $container['summary']['#markup'] = $this->wizardHelper->buildSummaryMarkup([
      [$this->t('Agency'),           $agency?->label()  ?? '-'],
      [$this->t('Appointment type'), $type?->label()    ?? '-'],
      [$this->t('Adviser'),          $adviser?->label() ?? '-'],
      [$this->t('Date'),             $data['appointment_day']  ?? '-'],
      [$this->t('Time'),             $data['appointment_time'] ?? '-'],
      [$this->t('Full name'),        $data['customer_name']    ?? '-'],
      [$this->t('Email'),            $data['customer_email']   ?? '-'],
      [$this->t('Phone'),            $data['customer_phone']   ?? '-'],
      [$this->t('Notes'),            $data['notes']            ?? '-'],
    ]);
  }

}