<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\appointment\Service\AppointmentManagementHelper;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\appointment\Service\AppointmentWizardHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public multi-step appointment modify form.
 */
final class AppointmentModifyForm extends AppointmentManagementBaseForm {

  private const TOTAL_STEPS = 6;
  private const EDIT_WIZARD_KEY = 'edit_wizard_data';

  public function __construct(
    PrivateTempStoreFactory $tempStoreFactory,
    EntityTypeManagerInterface $entityTypeManager,
    AppointmentManagementHelper $managementHelper,
    protected AppointmentWizardHelper $wizardHelper,
  ) {
    parent::__construct($tempStoreFactory, $entityTypeManager, $managementHelper);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('appointment.management_helper'),
      $container->get('appointment.wizard_helper'),
    );
  }

  public function getFormId(): string {
    return 'appointment_modify_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = TRUE;
    $form['#prefix'] = '<div id="booking-form-wrapper">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'appointment/booking_wizard';

    $appointment_to_edit = $this->managementHelper->getVerifiedAppointment();

    if (!$appointment_to_edit) {
      $form['expired']['#markup'] = '<p>' . $this->t('Your verification session has expired. Please verify again.') . '</p>';
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['back_to_lookup'] = [
        '#type' => 'submit',
        '#value' => $this->t('Verify appointment'),
        '#submit' => ['::cancelEditSubmit'],
        '#limit_validation_errors' => [],
      ];
      return $form;
    }

    $status = (string) ($appointment_to_edit->get('appointment_status')->value ?? 'pending');
    if ($status === 'cancelled') {
      $form['cancelled']['#markup'] = '<p>' . $this->t('Cancelled appointments cannot be modified.') . '</p>';
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['back_to_lookup'] = [
        '#type' => 'submit',
        '#value' => $this->t('Verify appointment'),
        '#submit' => ['::cancelEditSubmit'],
        '#limit_validation_errors' => [],
      ];
      return $form;
    }

    $this->initializeEditWizardData($appointment_to_edit);

    $step = $this->currentStep($form_state);
    $data = $this->loadWizardData();
    if ($step === 4) {
      $form['#attached']['library'][] = 'appointment/booking_calendar';
    }

    $form['title']['#markup'] = '<h2>' . $this->t('Modify appointment') . '</h2><p>' . $this->t('Follow the same steps to update your appointment.') . '</p>';
    $form['progress']['#markup'] = $this->wizardHelper->buildProgressMarkup($step);
    $form['wizard'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['booking-wizard']],
    ];

    match ($step) {
      1 => $this->buildStepAgency($form['wizard'], $data),
      2 => $this->buildStepType($form['wizard'], $data),
      3 => $this->buildStepAdviser($form['wizard'], $data),
      4 => $this->buildStepDate($form['wizard'], $form_state, $data, (int) $appointment_to_edit->id()),
      5 => $this->buildStepCustomer($form['wizard'], $data),
      default => $this->buildStepConfirm($form['wizard'], $data),
    };

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['cancel_edit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel edit'),
      '#submit' => ['::cancelEditSubmit'],
      '#limit_validation_errors' => [],
      '#ajax' => ['callback' => '::ajaxRefresh', 'wrapper' => 'booking-form-wrapper'],
    ];

    if ($step > 1) {
      $form['actions']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => ['::backSubmit'],
        '#limit_validation_errors' => [],
        '#ajax' => ['callback' => '::ajaxRefresh', 'wrapper' => 'booking-form-wrapper'],
      ];
    }

    if ($step < self::TOTAL_STEPS) {
      $form['actions']['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Next'),
        '#submit' => ['::nextSubmit'],
        '#limit_validation_errors' => $this->currentStepValidationScope($step),
        '#ajax' => ['callback' => '::ajaxRefresh', 'wrapper' => 'booking-form-wrapper'],
      ];
    }
    else {
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save changes'),
      ];
    }

    return $form;
  }

  public function ajaxRefresh(array &$form): array {
    return $form;
  }

  public function nextSubmit(array &$form, FormStateInterface $form_state): void {
    $step = $this->currentStep($form_state);
    $data = $this->loadWizardData();
    $this->storeStepData($step, $form_state, $data);
    $this->saveWizardData($data);
    $form_state->set('step', min(self::TOTAL_STEPS, $step + 1));
    $form_state->setRebuild(TRUE);
  }

  public function backSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', max(1, $this->currentStep($form_state) - 1));
    $form_state->setRebuild(TRUE);
  }

  public function cancelEditSubmit(array &$form, FormStateInterface $form_state): void {
    $this->clearWizardData();
    $this->managementHelper->clearVerification();
    $form_state->setRedirect('appointment.manage_lookup');
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $step = $this->currentStep($form_state);
    $appointment_to_edit = $this->managementHelper->getVerifiedAppointment();

    if (!$appointment_to_edit) {
      $form_state->setErrorByName('wizard][appointment_day', $this->t('Your verification session has expired.'));
      return;
    }

    $simple = [
      1 => ['agency', $this->t('Please choose an agency.')],
      2 => ['appointment_type', $this->t('Please choose an appointment type.')],
      3 => ['adviser', $this->t('Please choose an adviser.')],
    ];

    if (isset($simple[$step]) && !$this->value($form_state, $simple[$step][0])) {
      $form_state->setErrorByName('wizard][' . $simple[$step][0], $simple[$step][1]);
      return;
    }

    if ($step === 4) {
      $date = (string) ($this->value($form_state, 'appointment_date') ?? '');
      if ($date === '' || strlen($date) < 16) {
        $form_state->setErrorByName('wizard][appointment_date', $this->t('Please select a time slot on the calendar.'));
        return;
      }
      // Verify slot is still available.
      $day = substr($date, 0, 10);
      $time = substr($date, 11, 5);
      $data = $this->loadWizardData();
      $slots = $this->wizardHelper->getAvailableHalfHourSlots((int) ($data['adviser'] ?? 0), $day, (int) $appointment_to_edit->id());
      if (!$this->isCurrentSlot($appointment_to_edit, $day, $time) && !isset($slots[$time])) {
        $form_state->setErrorByName('wizard][appointment_date', $this->t('Selected slot is no longer available.'));
      }
    }

    if ($step === 5) {
      foreach (['customer_name' => $this->t('Name is required.'), 'customer_email' => $this->t('Email is required.'), 'customer_phone' => $this->t('Phone is required.')] as $field => $message) {
        if (!$this->value($form_state, $field)) {
          $form_state->setErrorByName('wizard][' . $field, $message);
        }
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $appointment_to_edit = $this->managementHelper->getVerifiedAppointment();

    if (!$appointment_to_edit) {
      $this->messenger()->addError($this->t('Your verification session has expired.'));
      $form_state->setRedirect('appointment.manage_lookup');
      return;
    }

    $data = $this->loadWizardData();

    $required = ['agency', 'appointment_type', 'adviser', 'appointment_day', 'appointment_time', 'appointment_date', 'customer_name', 'customer_email', 'customer_phone'];
    foreach ($required as $key) {
      if (empty($data[$key])) {
        $this->messenger()->addError($this->t('Please complete all steps before saving.'));
        $form_state->set('step', 1);
        $form_state->setRebuild(TRUE);
        return;
      }
    }

    $slots = $this->wizardHelper->getAvailableHalfHourSlots((int) $data['adviser'], (string) $data['appointment_day'], (int) $appointment_to_edit->id());
    if (!$this->isCurrentSlot($appointment_to_edit, (string) $data['appointment_day'], (string) $data['appointment_time']) && !isset($slots[(string) $data['appointment_time']])) {
      $this->messenger()->addError($this->t('Selected slot is no longer available. Please choose another time.'));
      $form_state->set('step', 4);
      $form_state->setRebuild(TRUE);
      return;
    }

    $appointment_to_edit->set('appointment_agency', (int) $data['agency']);
    $appointment_to_edit->set('appointment_type', (int) $data['appointment_type']);
    $appointment_to_edit->set('appointment_adviser', (int) $data['adviser']);
    $appointment_to_edit->set('appointment_date', (string) $data['appointment_date']);
    $appointment_to_edit->set('customer_name', (string) $data['customer_name']);
    $appointment_to_edit->set('customer_email', (string) $data['customer_email']);
    $appointment_to_edit->set('customer_phone', (string) $data['customer_phone']);
    $appointment_to_edit->set('notes', (string) ($data['notes'] ?? ''));

    if ((string) ($appointment_to_edit->get('appointment_status')->value ?? '') !== 'cancelled') {
      $appointment_to_edit->set('appointment_status', 'confirmed');
      $appointment_to_edit->set('status', TRUE);
    }

    $appointment_to_edit->save();
    $this->managementHelper->sendAppointmentMail('modified', $appointment_to_edit);

    $reference = (string) ($appointment_to_edit->get('reference')->value ?? '-');
    $this->clearWizardData();
    // Keep verification alive so the user can modify again immediately.
    $form_state->set('step', 1);
    $this->messenger()->addStatus($this->t('Your appointment has been modified. Reference: @reference', ['@reference' => $reference]));
    $form_state->setRedirect('appointment.manage_actions');
  }

  // ---------------------------------------------------------------------------
  // Step builders
  // ---------------------------------------------------------------------------

  private function buildStepAgency(array &$container, array $data): void {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('agency')->loadMultiple() as $agency) {
      $options[$agency->id()] = $agency->label();
    }
    $container['title']['#markup'] = '<h3>1. ' . $this->t('Choose an agency') . '</h3>';
    $container['agency'] = ['#type' => 'radios', '#options' => $options, '#default_value' => $data['agency'] ?? NULL, '#required' => TRUE];
  }

  private function buildStepType(array &$container, array $data): void {
    $container['title']['#markup'] = '<h3>2. ' . $this->t('Choose appointment type') . '</h3>';
    $options = $this->wizardHelper->getTypeOptionsForAgency((int) ($data['agency'] ?? 0));
    if (empty($options)) {
      $container['empty']['#markup'] = '<p>' . $this->t('No appointment type is configured for this agency.') . '</p>';
      return;
    }
    $container['appointment_type'] = ['#type' => 'radios', '#options' => $options, '#default_value' => $data['appointment_type'] ?? NULL, '#required' => TRUE];
  }

  private function buildStepAdviser(array &$container, array $data): void {
    $container['title']['#markup'] = '<h3>3. ' . $this->t('Choose adviser') . '</h3>';
    $options = $this->wizardHelper->getAdviserOptions((int) ($data['agency'] ?? 0), (int) ($data['appointment_type'] ?? 0));
    if (empty($options)) {
      $container['empty']['#markup'] = '<p>' . $this->t('No adviser matches your agency and type.') . '</p>';
      return;
    }
    $container['adviser'] = ['#type' => 'radios', '#options' => $options, '#default_value' => $data['adviser'] ?? NULL, '#required' => TRUE];
  }
  
  private function buildStepDate(array &$container, FormStateInterface $form_state, array $data, int $exclude_appointment_id): void {
    $container['title']['#markup'] = '<h3>4. ' . $this->t('Choose date and time') . '</h3>';

    $adviser_id = (int) ($data['adviser'] ?? 0);
    $existing_date = (string) ($data['appointment_date'] ?? '');

    $container['calendar_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'appointment-calendar',
        'data-adviser' => $adviser_id,
        'data-slots-url' => '/appointment/slots',
        'data-exclude-id' => $exclude_appointment_id,
      ],
    ];

    $container['appointment_date'] = [
      '#type' => 'hidden',
      '#attributes' => ['id' => 'appointment-selected-date'],
      '#default_value' => $existing_date,
    ];
  }

  private function buildStepCustomer(array &$container, array $data): void {
    $container['title']['#markup'] = '<h3>5. ' . $this->t('Your information') . '</h3>';
    $container['customer_name'] = ['#type' => 'textfield', '#title' => $this->t('Full name'), '#default_value' => $data['customer_name'] ?? '', '#required' => TRUE];
    $container['customer_email'] = ['#type' => 'email', '#title' => $this->t('Email'), '#default_value' => $data['customer_email'] ?? '', '#required' => TRUE];
    $container['customer_phone'] = ['#type' => 'tel', '#title' => $this->t('Phone'), '#default_value' => $data['customer_phone'] ?? '', '#required' => TRUE];
    $container['notes'] = ['#type' => 'textarea', '#title' => $this->t('Notes'), '#default_value' => $data['notes'] ?? ''];
  }

  private function buildStepConfirm(array &$container, array $data): void {
    $container['title']['#markup'] = '<h3>6. ' . $this->t('Review and confirm') . '</h3>';

    $agency = $this->entityTypeManager->getStorage('agency')->load((int) ($data['agency'] ?? 0));
    $type = $this->entityTypeManager->getStorage('taxonomy_term')->load((int) ($data['appointment_type'] ?? 0));
    $adviser = $this->entityTypeManager->getStorage('user')->load((int) ($data['adviser'] ?? 0));

    $container['summary']['#markup'] = $this->wizardHelper->buildSummaryMarkup([
      [$this->t('Reference'), $data['reference'] ?? '-'],
      [$this->t('Agency'), $agency?->label() ?? '-'],
      [$this->t('Appointment type'), $type?->label() ?? '-'],
      [$this->t('Adviser'), $adviser?->label() ?? '-'],
      [$this->t('Date'), $data['appointment_day'] ?? '-'],
      [$this->t('Time'), $data['appointment_time'] ?? '-'],
      [$this->t('Full name'), $data['customer_name'] ?? '-'],
      [$this->t('Email'), $data['customer_email'] ?? '-'],
      [$this->t('Phone'), $data['customer_phone'] ?? '-'],
      [$this->t('Notes'), $data['notes'] ?? '-'],
    ]);
  }

  // ---------------------------------------------------------------------------
  // Wizard data
  // ---------------------------------------------------------------------------

  private function store(): PrivateTempStore {
    return $this->tempStoreFactory->get(self::STORE);
  }

  private function loadWizardData(): array {
    return $this->store()->get(self::EDIT_WIZARD_KEY) ?? [];
  }

  private function saveWizardData(array $data): void {
    $this->store()->set(self::EDIT_WIZARD_KEY, $data);
  }

  private function clearWizardData(): void {
    $this->store()->delete(self::EDIT_WIZARD_KEY);
  }

  private function storeStepData(int $step, FormStateInterface $form_state, array &$data): void {
    if ($step === 1) {
      $new = (int) ($this->value($form_state, 'agency') ?? 0);
      if ($new !== (int) ($data['agency'] ?? 0)) {
        unset($data['appointment_type'], $data['adviser'], $data['appointment_day'], $data['appointment_time'], $data['appointment_date']);
      }
      $data['agency'] = $new;
      return;
    }
    if ($step === 2) {
      $new = (int) ($this->value($form_state, 'appointment_type') ?? 0);
      if ($new !== (int) ($data['appointment_type'] ?? 0)) {
        unset($data['adviser'], $data['appointment_day'], $data['appointment_time'], $data['appointment_date']);
      }
      $data['appointment_type'] = $new;
      return;
    }
    if ($step === 3) {
      $new = (int) ($this->value($form_state, 'adviser') ?? 0);
      if ($new !== (int) ($data['adviser'] ?? 0)) {
        unset($data['appointment_day'], $data['appointment_time'], $data['appointment_date']);
      }
      $data['adviser'] = $new;
      return;
    }
    if ($step === 4) {
      $date = (string) ($this->value($form_state, 'appointment_date') ?? '');
      $data['appointment_date'] = $date;
      $data['appointment_day'] = strlen($date) >= 10 ? substr($date, 0, 10) : '';
      $data['appointment_time'] = strlen($date) >= 16 ? substr($date, 11, 5) : '';
      return;
    }
    if ($step === 5) {
      $data['customer_name'] = (string) ($this->value($form_state, 'customer_name') ?? '');
      $data['customer_email'] = (string) ($this->value($form_state, 'customer_email') ?? '');
      $data['customer_phone'] = (string) ($this->value($form_state, 'customer_phone') ?? '');
      $data['notes'] = (string) ($this->value($form_state, 'notes') ?? '');
    }
  }

  // ---------------------------------------------------------------------------
  // Edit-mode helpers
  // ---------------------------------------------------------------------------

  private function initializeEditWizardData(ContentEntityInterface $appointment): void {
    $data = $this->loadWizardData();
    $appointment_id = (int) $appointment->id();

    if ((int) ($data['appointment_id'] ?? 0) === $appointment_id) {
      return;
    }

    $date_value = (string) ($appointment->get('appointment_date')->value ?? '');
    $day = strlen($date_value) >= 10 ? substr($date_value, 0, 10) : '';
    $time = strlen($date_value) >= 16 ? substr($date_value, 11, 5) : '';

    $this->saveWizardData([
      'appointment_id' => $appointment_id,
      'reference' => (string) ($appointment->get('reference')->value ?? '-'),
      'agency' => (int) ($appointment->get('appointment_agency')->target_id ?? 0),
      'appointment_type' => (int) ($appointment->get('appointment_type')->target_id ?? 0),
      'adviser' => (int) ($appointment->get('appointment_adviser')->target_id ?? 0),
      'appointment_day' => $day,
      'appointment_time' => $time,
      'appointment_date' => ($day !== '' && $time !== '') ? ($day . 'T' . $time . ':00') : '',
      'customer_name' => (string) ($appointment->get('customer_name')->value ?? ''),
      'customer_email' => (string) ($appointment->get('customer_email')->value ?? ''),
      'customer_phone' => (string) ($appointment->get('customer_phone')->value ?? ''),
      'notes' => (string) ($appointment->get('notes')->value ?? ''),
    ]);
  }

  private function isCurrentSlot(ContentEntityInterface $appointment, string $day, string $time): bool {
    $current = (string) ($appointment->get('appointment_date')->value ?? '');
    return strlen($current) >= 16
      && substr($current, 0, 10) === $day
      && substr($current, 11, 5) === $time;
  }

  // ---------------------------------------------------------------------------
  // Utilities
  // ---------------------------------------------------------------------------

  private function value(FormStateInterface $form_state, string $key): mixed {
    return $form_state->getValue(['wizard', $key]) ?? $form_state->getValue($key);
  }

  private function currentStep(FormStateInterface $form_state): int {
    $step = max(1, min(self::TOTAL_STEPS, (int) ($form_state->get('step') ?? 1)));
    $form_state->set('step', $step);
    return $step;
  }

  private function currentStepValidationScope(int $step): array {
    return match ($step) {
      1 => [['wizard', 'agency']],
      2 => [['wizard', 'appointment_type']],
      3 => [['wizard', 'adviser']],
      4 => [['wizard', 'appointment_date']],
      5 => [['wizard', 'customer_name'], ['wizard', 'customer_email'], ['wizard', 'customer_phone']],
      default => [],
    };
  }

}