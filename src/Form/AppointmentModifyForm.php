<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\appointment\Service\AppointmentManagementHelper;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\appointment\Service\AppointmentWizardHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public multi-step appointment modify form.
 */
final class AppointmentModifyForm extends BookingFormBase {

  private const STORE_KEY = 'edit_wizard_data';

  public function __construct(
    PrivateTempStoreFactory $tempStoreFactory,
    EntityTypeManagerInterface $entityTypeManager,
    AppointmentWizardHelper $wizardHelper,
    protected AppointmentManagementHelper $managementHelper, // ADD THIS
  ) {
    parent::__construct($tempStoreFactory, $entityTypeManager, $wizardHelper);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('appointment.wizard_helper'),
      $container->get('appointment.management_helper'), // ADD THIS
    );
  }

  public function getFormId(): string {
    return 'appointment_modify_form';
  }

  protected function storeKey(): string {
    return self::STORE_KEY;
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $appointment = $this->managementHelper->getVerifiedAppointment();

    // --- session expired ---
    if (!$appointment) {
      $form['expired']['#markup'] = '<p>' . $this->t('Your verification session has expired. Please verify again.') . '</p>';
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['back_to_lookup'] = $this->cancelButton();
      return $form;
    }

    // --- cancelled appointment ---
    if ((string) ($appointment->get('appointment_status')->value ?? '') === 'cancelled') {
      $form['cancelled']['#markup'] = '<p>' . $this->t('Cancelled appointments cannot be modified.') . '</p>';
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['back_to_lookup'] = $this->cancelButton();
      return $form;
    }

    $this->initializeEditWizardData($appointment);

    $step = $this->currentStep($form_state);
    $data = $this->loadWizardData();

    $this->initWizardScaffold($form, $step);

    match ($step) {
      1       => $this->buildStepAgency($form['wizard'], $data),
      2       => $this->buildStepType($form['wizard'], $data),
      3       => $this->buildStepAdviser($form['wizard'], $data),
      4       => $this->buildStepDate($form['wizard'], $data, (int) $appointment->id()),
      5       => $this->buildStepCustomer($form['wizard'], $data),
      default => $this->buildStepConfirm($form['wizard'], $data, $appointment),
    };

    $this->addActions($form, $step, (string) $this->t('Save changes'), [
      'cancel_edit' => [
        '#type'                    => 'submit',
        '#value'                   => $this->t('Cancel edit'),
        '#submit'                  => ['::cancelEditSubmit'],
        '#limit_validation_errors' => [],
        '#ajax'                    => ['callback' => '::ajaxRefresh', 'wrapper' => 'booking-form-wrapper'],
      ],
    ]);

    return $form;
  }

  public function cancelEditSubmit(array &$form, FormStateInterface $form_state): void {
    $this->clearWizardData();
    $this->managementHelper->clearVerification();
    $form_state->setRedirect('appointment.manage_lookup');
  }

  protected function validateStepDate(FormStateInterface $form_state): void {
    $appointment = $this->managementHelper->getVerifiedAppointment();
    
    if (!$appointment) {
      $form_state->setErrorByName('wizard][appointment_date', $this->t('Your verification session has expired.'));
      return;
    }

    $date = (string) ($this->value($form_state, 'appointment_date') ?? '');
    if ($date === '' || strlen($date) < 16) {
      $form_state->setErrorByName('wizard][appointment_date', $this->t('Please select a time slot on the calendar.'));
      return;
    }

    $day   = substr($date, 0, 10);
    $time  = substr($date, 11, 5);
    $data  = $this->loadWizardData();
    
    // PASS THE APPOINTMENT ID TO EXCLUDE IT FROM BOOKED SLOTS
    $slots = $this->wizardHelper->getAvailableHalfHourSlots(
      (int) ($data['adviser'] ?? 0), 
      $day, 
      (int) $appointment->id()
    );

    if (!$this->isCurrentSlot($appointment, $day, $time) && !isset($slots[$time])) {
      $form_state->setErrorByName('wizard][appointment_date', $this->t('Selected slot is no longer available.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $appointment = $this->managementHelper->getVerifiedAppointment();

    if (!$appointment) {
      $this->messenger()->addError($this->t('Your verification session has expired.'));
      $form_state->setRedirect('appointment.manage_lookup');
      return;
    }

    $data = $this->loadWizardData();

    foreach (['agency', 'appointment_type', 'adviser', 'appointment_day', 'appointment_time', 'appointment_date', 'customer_name', 'customer_email', 'customer_phone'] as $key) {
      if (empty($data[$key])) {
        $this->messenger()->addError($this->t('Please complete all steps before saving.'));
        $form_state->set('step', 1);
        $form_state->setRebuild(TRUE);
        return;
      }
    }

    // Race-condition slot check — allow the appointment's own slot through.
    $slots = $this->wizardHelper->getAvailableHalfHourSlots(
      (int) $data['adviser'], 
      (string) $data['appointment_day'], 
      (int) $appointment->id()
    );

    if (!$this->isCurrentSlot($appointment, (string) $data['appointment_day'], (string) $data['appointment_time']) 
        && !isset($slots[(string) $data['appointment_time']])) {
      $this->messenger()->addError($this->t('Selected slot is no longer available. Please choose another time.'));
      $form_state->set('step', 4);
      $form_state->setRebuild(TRUE);
      return;
    }

    $appointment->set('appointment_agency',  (int)    $data['agency']);
    $appointment->set('appointment_type',    (int)    $data['appointment_type']);
    $appointment->set('appointment_adviser', (int)    $data['adviser']);
    $appointment->set('appointment_date',    (string) $data['appointment_date']);
    $appointment->set('customer_name',       (string) $data['customer_name']);
    $appointment->set('customer_email',      (string) $data['customer_email']);
    $appointment->set('customer_phone',      (string) $data['customer_phone']);
    $appointment->set('notes',               (string) ($data['notes'] ?? ''));

    if ((string) ($appointment->get('appointment_status')->value ?? '') !== 'cancelled') {
      $appointment->set('appointment_status', 'confirmed');
      $appointment->set('status', TRUE);
    }

    $appointment->save();
    $this->managementHelper->sendAppointmentMail('modified', $appointment);

    $reference = (string) ($appointment->get('reference')->value ?? '-');
    $this->clearWizardData();
    $form_state->set('step', 1);
    $this->messenger()->addStatus($this->t('Your appointment has been modified. Reference: @reference', ['@reference' => $reference]));
    $form_state->setRedirect('appointment.manage_actions');
  }

  private function buildStepConfirm(array &$container, array $data, ContentEntityInterface $appointment): void {
    $container['title']['#markup'] = '<h3>6. ' . $this->t('Review and confirm') . '</h3>';

    $agency  = $this->entityTypeManager->getStorage('agency')->load((int) ($data['agency'] ?? 0));
    $type    = $this->entityTypeManager->getStorage('taxonomy_term')->load((int) ($data['appointment_type'] ?? 0));
    $adviser = $this->entityTypeManager->getStorage('user')->load((int) ($data['adviser'] ?? 0));

    $container['summary']['#markup'] = $this->wizardHelper->buildSummaryMarkup([
      [$this->t('Reference'),        (string) ($appointment->get('reference')->value ?? '-')],
      [$this->t('Agency'),           $agency?->label()         ?? '-'],
      [$this->t('Appointment type'), $type?->label()           ?? '-'],
      [$this->t('Adviser'),          $adviser?->label()        ?? '-'],
      [$this->t('Date'),             $data['appointment_day']  ?? '-'],
      [$this->t('Time'),             $data['appointment_time'] ?? '-'],
      [$this->t('Full name'),        $data['customer_name']    ?? '-'],
      [$this->t('Email'),            $data['customer_email']   ?? '-'],
      [$this->t('Phone'),            $data['customer_phone']   ?? '-'],
      [$this->t('Notes'),            $data['notes']            ?? '-'],
    ]);
  }

  private function initializeEditWizardData(ContentEntityInterface $appointment): void {
    $data          = $this->loadWizardData();
    $appointment_id = (int) $appointment->id();

    if ((int) ($data['appointment_id'] ?? 0) === $appointment_id) {
      return;
    }

    $date_value = (string) ($appointment->get('appointment_date')->value ?? '');
    $day        = strlen($date_value) >= 10 ? substr($date_value, 0, 10) : '';
    $time       = strlen($date_value) >= 16 ? substr($date_value, 11, 5) : '';

    $this->saveWizardData([
      'appointment_id'   => $appointment_id,
      'agency'           => (int)    ($appointment->get('appointment_agency')->target_id  ?? 0),
      'appointment_type' => (int)    ($appointment->get('appointment_type')->target_id    ?? 0),
      'adviser'          => (int)    ($appointment->get('appointment_adviser')->target_id ?? 0),
      'appointment_day'  => $day,
      'appointment_time' => $time,
      'appointment_date' => ($day !== '' && $time !== '') ? ($day . 'T' . $time . ':00') : '',
      'customer_name'    => (string) ($appointment->get('customer_name')->value  ?? ''),
      'customer_email'   => (string) ($appointment->get('customer_email')->value ?? ''),
      'customer_phone'   => (string) ($appointment->get('customer_phone')->value ?? ''),
      'notes'            => (string) ($appointment->get('notes')->value          ?? ''),
    ]);
  }

  private function isCurrentSlot(ContentEntityInterface $appointment, string $day, string $time): bool {
    $current = (string) ($appointment->get('appointment_date')->value ?? '');
    return strlen($current) >= 16
      && substr($current, 0, 10) === $day
      && substr($current, 11, 5) === $time;
  }

  private function cancelButton(): array {
    return [
      '#type'                    => 'submit',
      '#value'                   => $this->t('Verify appointment'),
      '#submit'                  => ['::cancelEditSubmit'],
      '#limit_validation_errors' => [],
    ];
  }
}