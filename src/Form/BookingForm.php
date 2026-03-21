<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\appointment\Service\AppointmentWizardHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public multi-step booking form.
 */
final class BookingForm extends FormBase {

  private const TOTAL_STEPS = 6;
  private const STORE = 'appointment_booking';
  private const STORE_KEY = 'wizard_data';

  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AppointmentWizardHelper $wizardHelper,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('appointment.wizard_helper'),
    );
  }

  public function getFormId(): string {
    return 'appointment_booking_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $step = $this->currentStep($form_state);
    $data = $this->loadWizardData();

    $form['#tree'] = TRUE;
    $form['#prefix'] = '<div id="booking-form-wrapper">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'appointment/booking_wizard';
    $form['progress']['#markup'] = $this->wizardHelper->buildProgressMarkup($step);
    $form['wizard'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['booking-wizard']],
    ];

    match ($step) {
      1 => $this->buildStepAgency($form['wizard'], $data),
      2 => $this->buildStepType($form['wizard'], $data),
      3 => $this->buildStepAdviser($form['wizard'], $data),
      4 => $this->buildStepDate($form['wizard'], $form_state, $data),
      5 => $this->buildStepCustomer($form['wizard'], $data),
      default => $this->buildStepConfirm($form['wizard'], $data),
    };

    $form['actions'] = ['#type' => 'actions'];

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
        '#value' => $this->t('Confirm appointment'),
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

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $step = $this->currentStep($form_state);

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
      $day = (string) ($this->value($form_state, 'appointment_day') ?? '');
      $time = (string) ($this->value($form_state, 'appointment_time') ?? '');

      if ($day === '') {
        $form_state->setErrorByName('wizard][appointment_day', $this->t('Please choose a date.'));
      }
      if ($time === '') {
        $form_state->setErrorByName('wizard][appointment_time', $this->t('Please choose a time slot.'));
      }
      if ($day !== '' && $time !== '') {
        $data = $this->loadWizardData();
        $slots = $this->wizardHelper->getAvailableHalfHourSlots((int) ($data['adviser'] ?? 0), $day);
        if (!isset($slots[$time])) {
          $form_state->setErrorByName('wizard][appointment_time', $this->t('Selected time is not available for this adviser.'));
        }
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

    $slots = $this->wizardHelper->getAvailableHalfHourSlots((int) $data['adviser'], (string) $data['appointment_day']);
    if (!isset($slots[(string) $data['appointment_time']])) {
      $this->messenger()->addError($this->t('Selected slot is no longer available. Please choose another time.'));
      $form_state->set('step', 4);
      $form_state->setRebuild(TRUE);
      return;
    }

    /** @var \Drupal\appointment\Entity\AppointmentInterface $appointment */
    $appointment = $this->entityTypeManager->getStorage('appointment')->create([
      'label' => 'Appointment ' . date('Y-m-d H:i'),
      'appointment_agency' => (int) $data['agency'],
      'appointment_type' => (int) $data['appointment_type'],
      'appointment_adviser' => (int) $data['adviser'],
      'appointment_date' => $data['appointment_date'],
      'customer_name' => $data['customer_name'],
      'customer_email' => $data['customer_email'],
      'customer_phone' => $data['customer_phone'],
      'notes' => $data['notes'] ?? '',
      'appointment_status' => 'pending',
      'status' => TRUE,
    ]);
    $appointment->save();
    $this->wizardHelper->sendAppointmentMail('created', $appointment);

    $reference = (string) ($appointment->get('reference')->value ?? '');
    $this->clearWizardData();
    $form_state->set('step', 1);
    $this->messenger()->addStatus($this->t('Your appointment has been created. Reference: @reference', ['@reference' => $reference ?: '-']));
    $form_state->setRedirect('appointment.manage_lookup');
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

  private function buildStepDate(array &$container, FormStateInterface $form_state, array $data): void {
    $container['title']['#markup'] = '<h3>4. ' . $this->t('Choose date and time') . '</h3>';

    $adviser_id = (int) ($data['adviser'] ?? 0);
    $day = (string) ($this->value($form_state, 'appointment_day') ?? $data['appointment_day'] ?? '');
    $time = (string) ($this->value($form_state, 'appointment_time') ?? $data['appointment_time'] ?? '');
    $time_options = $day !== '' ? $this->wizardHelper->getAvailableHalfHourSlots($adviser_id, $day) : [];

    if ($time !== '' && !isset($time_options[$time])) {
      $time = '';
    }

    $container['appointment_day'] = [
      '#type' => 'date',
      '#title' => $this->t('Date'),
      '#default_value' => $day,
      '#required' => TRUE,
      '#ajax' => ['callback' => '::ajaxRefresh', 'wrapper' => 'booking-form-wrapper', 'event' => 'change'],
    ];
    $container['appointment_time'] = [
      '#type' => 'select',
      '#title' => $this->t('Time slot (30 min)'),
      '#options' => $time_options,
      '#empty_option' => $this->t('- Select a time -'),
      '#default_value' => $time ?: NULL,
      '#required' => TRUE,
    ];

    if ($day !== '' && empty($time_options)) {
      $container['no_slots']['#markup'] = '<p>' . $this->t('No available 30-minute slots for this adviser on the selected date.') . '</p>';
    }
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
    return $this->store()->get(self::STORE_KEY) ?? [];
  }

  private function saveWizardData(array $data): void {
    $this->store()->set(self::STORE_KEY, $data);
  }

  private function clearWizardData(): void {
    $this->store()->delete(self::STORE_KEY);
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
      $day = (string) ($this->value($form_state, 'appointment_day') ?? '');
      $time = (string) ($this->value($form_state, 'appointment_time') ?? '');
      $data['appointment_day'] = $day;
      $data['appointment_time'] = $time;
      if ($day !== '' && $time !== '') {
        $data['appointment_date'] = $day . 'T' . $time . ':00';
      }
      else {
        unset($data['appointment_date']);
      }
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
      4 => [['wizard', 'appointment_day'], ['wizard', 'appointment_time']],
      5 => [['wizard', 'customer_name'], ['wizard', 'customer_email'], ['wizard', 'customer_phone']],
      default => [],
    };
  }

}