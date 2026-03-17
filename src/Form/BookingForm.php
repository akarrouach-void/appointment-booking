<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public multi-step booking form.
 */
final class BookingForm extends FormBase {

  private const TOTAL_STEPS = 6;

  /**
   * TempStore factory.
   */
  private ?PrivateTempStoreFactory $tempStoreFactory = NULL;

  /**
   * Entity type manager.
   */
  private ?EntityTypeManagerInterface $entityTypeManager = NULL;

  /**
   * Constructs the booking form.
   */
  public function __construct(
    ?PrivateTempStoreFactory $temp_store_factory = NULL,
    ?EntityTypeManagerInterface $entity_type_manager = NULL,
  ) {
    $this->tempStoreFactory = $temp_store_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_booking_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->ensureServices();

    $step = $this->currentStep($form_state);
    $data = $this->loadWizardData();

    $form['#tree'] = TRUE;
    $form['#prefix'] = '<div id="booking-form-wrapper">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'appointment/booking_wizard';

    $form['progress'] = [
      '#type' => 'markup',
      '#markup' => $this->buildProgressMarkup($step),
    ];

    $form['wizard'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['booking-wizard']],
    ];

    switch ($step) {
      case 1:
        $this->buildStepAgency($form['wizard'], $data);
        break;

      case 2:
        $this->buildStepType($form['wizard'], $data);
        break;

      case 3:
        $this->buildStepAdviser($form['wizard'], $data);
        break;

      case 4:
        $this->buildStepDate($form['wizard'], $form_state, $data);
        break;

      case 5:
        $this->buildStepCustomer($form['wizard'], $data);
        break;

      case 6:
      default:
        $this->buildStepConfirm($form['wizard'], $data);
        break;
    }

    $form['actions'] = ['#type' => 'actions'];

    if ($step > 1) {
      $form['actions']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => ['::backSubmit'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'booking-form-wrapper',
        ],
      ];
    }

    if ($step < self::TOTAL_STEPS) {
      $form['actions']['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Next'),
        '#submit' => ['::nextSubmit'],
        '#limit_validation_errors' => $this->currentStepValidationScope($step),
        '#ajax' => [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'booking-form-wrapper',
        ],
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

  /**
   * AJAX callback.
   */
  public function ajaxRefresh(array &$form): array {
    return $form;
  }

  /**
   * Next step handler.
   */
  public function nextSubmit(array &$form, FormStateInterface $form_state): void {
    $this->ensureServices();

    $step = $this->currentStep($form_state);
    $data = $this->loadWizardData();

    $this->storeStepData($step, $form_state, $data);
    $this->saveWizardData($data);

    $form_state->set('step', min(self::TOTAL_STEPS, $step + 1));
    $form_state->setRebuild(TRUE);
  }

  /**
   * Back step handler.
   */
  public function backSubmit(array &$form, FormStateInterface $form_state): void {
    $step = $this->currentStep($form_state);
    $form_state->set('step', max(1, $step - 1));
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $this->ensureServices();

    $step = $this->currentStep($form_state);

    if ($step === 1 && !$this->value($form_state, 'agency')) {
      $form_state->setErrorByName('wizard][agency', $this->t('Please choose an agency.'));
    }

    if ($step === 2 && !$this->value($form_state, 'appointment_type')) {
      $form_state->setErrorByName('wizard][appointment_type', $this->t('Please choose an appointment type.'));
    }

    if ($step === 3 && !$this->value($form_state, 'adviser')) {
      $form_state->setErrorByName('wizard][adviser', $this->t('Please choose an adviser.'));
    }

    if ($step === 4) {
      $selected_day = (string) ($this->value($form_state, 'appointment_day') ?? '');
      $selected_time = (string) ($this->value($form_state, 'appointment_time') ?? '');

      if ($selected_day === '') {
        $form_state->setErrorByName('wizard][appointment_day', $this->t('Please choose a date.'));
      }

      if ($selected_time === '') {
        $form_state->setErrorByName('wizard][appointment_time', $this->t('Please choose a time slot.'));
      }

      if ($selected_day !== '' && $selected_time !== '') {
        $data = $this->loadWizardData();
        $adviser_id = (int) ($data['adviser'] ?? 0);
        $available_slots = $this->getAvailableHalfHourSlots($adviser_id, $selected_day);
        if (!isset($available_slots[$selected_time])) {
          $form_state->setErrorByName('wizard][appointment_time', $this->t('Selected time is not available for this adviser.'));
        }
      }
    }

    if ($step === 5) {
      if (!$this->value($form_state, 'customer_name')) {
        $form_state->setErrorByName('wizard][customer_name', $this->t('Name is required.'));
      }
      if (!$this->value($form_state, 'customer_email')) {
        $form_state->setErrorByName('wizard][customer_email', $this->t('Email is required.'));
      }
      if (!$this->value($form_state, 'customer_phone')) {
        $form_state->setErrorByName('wizard][customer_phone', $this->t('Phone is required.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->ensureServices();

    $data = $this->loadWizardData();

    $required = [
      'agency',
      'appointment_type',
      'adviser',
      'appointment_day',
      'appointment_time',
      'appointment_date',
      'customer_name',
      'customer_email',
      'customer_phone',
    ];

    foreach ($required as $key) {
      if (empty($data[$key])) {
        $this->messenger()->addError($this->t('Please complete all steps before confirming.'));
        $form_state->set('step', 1);
        $form_state->setRebuild(TRUE);
        return;
      }
    }

    $available_slots = $this->getAvailableHalfHourSlots((int) $data['adviser'], (string) $data['appointment_day']);
    if (!isset($available_slots[(string) $data['appointment_time']])) {
      $this->messenger()->addError($this->t('Selected slot is no longer available. Please choose another time.'));
      $form_state->set('step', 4);
      $form_state->setRebuild(TRUE);
      return;
    }

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

    $this->tempStoreFactory->get('appointment_booking')->delete('wizard_data');
    $form_state->set('step', 1);

    $this->messenger()->addStatus($this->t('Your appointment has been created.'));
    $form_state->setRedirect('entity.appointment.canonical', ['appointment' => $appointment->id()]);
  }

  /**
   * Builds step 1.
   */
  private function buildStepAgency(array &$container, array $data): void {
    $options = [];
    $agencies = $this->entityTypeManager->getStorage('agency')->loadMultiple();

    foreach ($agencies as $agency) {
      $options[$agency->id()] = $agency->label();
    }

    $container['title'] = [
      '#type' => 'markup',
      '#markup' => '<h3>1. ' . $this->t('Choose an agency') . '</h3>',
    ];

    $container['agency'] = [
      '#type' => 'radios',
      '#options' => $options,
      '#default_value' => $data['agency'] ?? NULL,
      '#required' => TRUE,
    ];
  }

  /**
   * Builds step 2.
   */
  private function buildStepType(array &$container, array $data): void {
    $container['title'] = [
      '#type' => 'markup',
      '#markup' => '<h3>2. ' . $this->t('Choose appointment type') . '</h3>',
    ];

    $agency_id = (int) ($data['agency'] ?? 0);
    $options = $this->getTypeOptionsForAgency($agency_id);

    if (empty($options)) {
      $container['empty'] = [
        '#markup' => '<p>' . $this->t('No appointment type is configured for this agency.') . '</p>',
      ];
      return;
    }

    $container['appointment_type'] = [
      '#type' => 'radios',
      '#options' => $options,
      '#default_value' => $data['appointment_type'] ?? NULL,
      '#required' => TRUE,
    ];
  }

  /**
   * Builds step 3.
   */
  private function buildStepAdviser(array &$container, array $data): void {
    $container['title'] = [
      '#type' => 'markup',
      '#markup' => '<h3>3. ' . $this->t('Choose adviser') . '</h3>',
    ];

    $agency_id = (int) ($data['agency'] ?? 0);
    $type_id = (int) ($data['appointment_type'] ?? 0);
    $options = $this->getAdviserOptions($agency_id, $type_id);

    if (empty($options)) {
      $container['empty'] = [
        '#markup' => '<p>' . $this->t('No adviser matches your agency and type.') . '</p>',
      ];
      return;
    }

    $container['adviser'] = [
      '#type' => 'radios',
      '#options' => $options,
      '#default_value' => $data['adviser'] ?? NULL,
      '#required' => TRUE,
    ];
  }

  /**
   * Builds step 4.
   */
  private function buildStepDate(array &$container, FormStateInterface $form_state, array $data): void {
    $container['title'] = [
      '#type' => 'markup',
      '#markup' => '<h3>4. ' . $this->t('Choose date and time') . '</h3>',
    ];

    $adviser_id = (int) ($data['adviser'] ?? 0);
    $selected_day = (string) ($this->value($form_state, 'appointment_day') ?? ($data['appointment_day'] ?? ''));
    $selected_time = (string) ($this->value($form_state, 'appointment_time') ?? ($data['appointment_time'] ?? ''));

    $container['appointment_day'] = [
      '#type' => 'date',
      '#title' => $this->t('Date'),
      '#default_value' => $selected_day,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::ajaxRefresh',
        'wrapper' => 'booking-form-wrapper',
        'event' => 'change',
      ],
    ];

    $time_options = $selected_day !== '' ? $this->getAvailableHalfHourSlots($adviser_id, $selected_day) : [];
    if ($selected_time !== '' && !isset($time_options[$selected_time])) {
      $selected_time = '';
    }

    $container['appointment_time'] = [
      '#type' => 'select',
      '#title' => $this->t('Time slot (30 min)'),
      '#options' => $time_options,
      '#empty_option' => $this->t('- Select a time -'),
      '#default_value' => $selected_time !== '' ? $selected_time : NULL,
      '#required' => TRUE,
    ];

    if ($selected_day !== '' && empty($time_options)) {
      $container['no_slots'] = [
        '#markup' => '<p>' . $this->t('No available 30-minute slots for this adviser on the selected date.') . '</p>',
      ];
    }
  }

  /**
   * Builds step 5.
   */
  private function buildStepCustomer(array &$container, array $data): void {
    $container['title'] = [
      '#type' => 'markup',
      '#markup' => '<h3>5. ' . $this->t('Your information') . '</h3>',
    ];

    $container['customer_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full name'),
      '#default_value' => $data['customer_name'] ?? '',
      '#required' => TRUE,
    ];

    $container['customer_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $data['customer_email'] ?? '',
      '#required' => TRUE,
    ];

    $container['customer_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone'),
      '#default_value' => $data['customer_phone'] ?? '',
      '#required' => TRUE,
    ];

    $container['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#default_value' => $data['notes'] ?? '',
    ];
  }

  /**
   * Builds step 6.
   */
  private function buildStepConfirm(array &$container, array $data): void {
    $container['title'] = [
      '#type' => 'markup',
      '#markup' => '<h3>6. ' . $this->t('Review and confirm') . '</h3>',
    ];

    $agency = $this->entityTypeManager->getStorage('agency')->load((int) ($data['agency'] ?? 0));
    $type = $this->entityTypeManager->getStorage('taxonomy_term')->load((int) ($data['appointment_type'] ?? 0));
    $adviser = $this->entityTypeManager->getStorage('user')->load((int) ($data['adviser'] ?? 0));

    $rows = [
      [$this->t('Agency'), $agency ? $agency->label() : '-'],
      [$this->t('Appointment type'), $type ? $type->label() : '-'],
      [$this->t('Adviser'), $adviser ? $adviser->label() : '-'],
      [$this->t('Date'), $data['appointment_day'] ?? '-'],
      [$this->t('Time'), $data['appointment_time'] ?? '-'],
      [$this->t('Date & time (storage)'), !empty($data['appointment_date']) ? $data['appointment_date'] : '-'],
      [$this->t('Full name'), $data['customer_name'] ?? '-'],
      [$this->t('Email'), $data['customer_email'] ?? '-'],
      [$this->t('Phone'), $data['customer_phone'] ?? '-'],
      [$this->t('Notes'), $data['notes'] ?? '-'],
    ];

    $items = '';
    foreach ($rows as [$label, $value]) {
      $items .= '<div class="booking-summary-row">'
        . '<div class="booking-summary-label">' . Html::escape((string) $label) . '</div>'
        . '<div class="booking-summary-value">' . Html::escape((string) $value) . '</div>'
        . '</div>';
    }

    $container['summary'] = [
      '#type' => 'markup',
      '#markup' => '<div class="booking-summary">'
        . '<p class="booking-summary-intro">' . $this->t('Please verify all information before confirming your appointment.') . '</p>'
        . $items
        . '</div>',
    ];
  }

  /**
   * Returns available 30-minute slots from adviser working hours.
   */
  private function getAvailableHalfHourSlots(int $adviser_id, string $date): array {
    if ($adviser_id <= 0 || $date === '') {
      return [];
    }

    $date_obj = date_create_immutable($date);
    if (!$date_obj) {
      return [];
    }

    $profile = $this->getAdviserProfile($adviser_id);
    if (!$profile instanceof ContentEntityInterface || !$profile->hasField('field_working_hours')) {
      return [];
    }

    $weekday = (int) $date_obj->format('w');
    $slots = [];

    foreach ($profile->get('field_working_hours')->getValue() as $row) {
      if ((int) ($row['day'] ?? -1) !== $weekday) {
        continue;
      }

      $start = (int) ($row['starthours'] ?? 0);
      $end = (int) ($row['endhours'] ?? 0);
      if ($start <= 0 || $end <= $start) {
        continue;
      }

      $start_minutes = $this->officeHoursToMinutes($start);
      $end_minutes = $this->officeHoursToMinutes($end);

      for ($cursor = $start_minutes; $cursor + 30 <= $end_minutes; $cursor += 30) {
        $time = $this->minutesToTime($cursor);
        $slots[$time] = $time;
      }
    }

    if (empty($slots)) {
      return [];
    }

    $booked = $this->getBookedTimesForDate($adviser_id, $date);
    foreach ($booked as $time) {
      unset($slots[$time]);
    }

    return $slots;
  }

  /**
   * Returns adviser profile entity for a given user id.
   */
  private function getAdviserProfile(int $adviser_id): ?ContentEntityInterface {
    $profiles = $this->entityTypeManager
      ->getStorage('profile')
      ->loadByProperties(['uid' => $adviser_id, 'type' => 'adviser']);

    $profile = reset($profiles);
    return $profile instanceof ContentEntityInterface ? $profile : NULL;
  }

  /**
   * Returns booked HH:MM values for adviser/date, excluding cancelled.
   */
  private function getBookedTimesForDate(int $adviser_id, string $date): array {
    $day_start = $date . 'T00:00:00';
    $day_end = (new DrupalDateTime($date . ' +1 day'))->format('Y-m-d') . 'T00:00:00';

    $storage = $this->entityTypeManager->getStorage('appointment');
    $query = $storage->getQuery();
    $query->accessCheck(FALSE);
    $query->condition('appointment_adviser', $adviser_id);
    $query->condition('appointment_date', $day_start, '>=');
    $query->condition('appointment_date', $day_end, '<');
    $ids = $query->execute();

    if (empty($ids)) {
      return [];
    }

    $booked = [];
    $appointments = $storage->loadMultiple($ids);
    foreach ($appointments as $appointment) {
      if ($appointment instanceof ContentEntityInterface && $appointment->hasField('appointment_status')) {
        $status = (string) ($appointment->get('appointment_status')->value ?? '');
        if ($status === 'cancelled') {
          continue;
        }
      }

      if (!$appointment instanceof ContentEntityInterface || !$appointment->hasField('appointment_date')) {
        continue;
      }

      $value = (string) ($appointment->get('appointment_date')->value ?? '');
      if (strlen($value) >= 16) {
        $booked[] = substr($value, 11, 5);
      }
    }

    return array_values(array_unique($booked));
  }

  /**
   * Converts office-hours int (e.g. 930) to minutes.
   */
  private function officeHoursToMinutes(int $hhmm): int {
    $hours = intdiv($hhmm, 100);
    $minutes = $hhmm % 100;
    return ($hours * 60) + $minutes;
  }

  /**
   * Converts minutes to HH:MM.
   */
  private function minutesToTime(int $minutes): string {
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    return sprintf('%02d:%02d', $hours, $mins);
  }

  /**
   * Returns appointment type options for selected agency.
   */
  private function getTypeOptionsForAgency(int $agency_id): array {
    if ($agency_id <= 0) {
      return [];
    }

    $agency = $this->entityTypeManager->getStorage('agency')->load($agency_id);
    if (!$agency instanceof ContentEntityInterface || !$agency->hasField('field_specializations')) {
      return [];
    }

    $term_ids = array_column($agency->get('field_specializations')->getValue(), 'target_id');
    if (empty($term_ids)) {
      return [];
    }

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($term_ids);
    $options = [];
    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }

    return $options;
  }

  /**
   * Returns advisers filtered by agency and specialization.
   */
  private function getAdviserOptions(int $agency_id, int $type_id): array {
    if ($agency_id <= 0 || $type_id <= 0) {
      return [];
    }

    $profile_query = $this->entityTypeManager->getStorage('profile')->getQuery();
    $profile_query->accessCheck(FALSE);
    $profile_query->condition('type', 'adviser');
    $profile_query->condition('field_agency.target_id', $agency_id);
    $profile_query->condition('field_specializations.target_id', $type_id);
    $profile_ids = $profile_query->execute();

    if (empty($profile_ids)) {
      return [];
    }

    $profiles = $this->entityTypeManager->getStorage('profile')->loadMultiple($profile_ids);
    $options = [];

    foreach ($profiles as $profile) {
      if (!$profile instanceof ContentEntityInterface || !$profile->hasField('uid')) {
        continue;
      }

      $uid = (int) ($profile->get('uid')->target_id ?? 0);
      if ($uid <= 0) {
        continue;
      }

      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      if ($user) {
        $options[$uid] = $user->label();
      }
    }

    return $options;
  }

  /**
   * Stores current step values to tempstore.
   */
  private function storeStepData(int $step, FormStateInterface $form_state, array &$data): void {
    if ($step === 1) {
      $new_agency = (int) ($this->value($form_state, 'agency') ?? 0);
      $old_agency = (int) ($data['agency'] ?? 0);
      $data['agency'] = $new_agency;

      if ($new_agency !== $old_agency) {
        unset($data['appointment_type'], $data['adviser'], $data['appointment_day'], $data['appointment_time'], $data['appointment_date']);
      }
      return;
    }

    if ($step === 2) {
      $new_type = (int) ($this->value($form_state, 'appointment_type') ?? 0);
      $old_type = (int) ($data['appointment_type'] ?? 0);
      $data['appointment_type'] = $new_type;

      if ($new_type !== $old_type) {
        unset($data['adviser'], $data['appointment_day'], $data['appointment_time'], $data['appointment_date']);
      }
      return;
    }

    if ($step === 3) {
      $new_adviser = (int) ($this->value($form_state, 'adviser') ?? 0);
      $old_adviser = (int) ($data['adviser'] ?? 0);
      $data['adviser'] = $new_adviser;

      if ($new_adviser !== $old_adviser) {
        unset($data['appointment_day'], $data['appointment_time'], $data['appointment_date']);
      }
      return;
    }

    if ($step === 4) {
      $selected_day = (string) ($this->value($form_state, 'appointment_day') ?? '');
      $selected_time = (string) ($this->value($form_state, 'appointment_time') ?? '');

      $data['appointment_day'] = $selected_day;
      $data['appointment_time'] = $selected_time;

      if ($selected_day !== '' && $selected_time !== '') {
        $data['appointment_date'] = $selected_day . 'T' . $selected_time . ':00';
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

  /**
   * Gets current step from form state.
   */
  private function currentStep(FormStateInterface $form_state): int {
    $step = (int) ($form_state->get('step') ?? 1);
    if ($step < 1 || $step > self::TOTAL_STEPS) {
      $step = 1;
      $form_state->set('step', 1);
    }
    return $step;
  }

  /**
   * Reads value from wizard container.
   */
  private function value(FormStateInterface $form_state, string $key): mixed {
    $value = $form_state->getValue(['wizard', $key]);
    if ($value !== NULL) {
      return $value;
    }
    return $form_state->getValue($key);
  }

  /**
   * Loads stored wizard data.
   */
  private function loadWizardData(): array {
    $this->ensureServices();
    return $this->tempStoreFactory->get('appointment_booking')->get('wizard_data') ?? [];
  }

  /**
   * Saves wizard data.
   */
  private function saveWizardData(array $data): void {
    $this->ensureServices();
    $this->tempStoreFactory->get('appointment_booking')->set('wizard_data', $data);
  }

  /**
   * Ensures required services are available (also after AJAX rebuilds).
   */
  private function ensureServices(): void {
    if ($this->tempStoreFactory === NULL) {
      $this->tempStoreFactory = \Drupal::service('tempstore.private');
    }

    if ($this->entityTypeManager === NULL) {
      $this->entityTypeManager = \Drupal::entityTypeManager();
    }
  }

  /**
   * Step validation scopes for Next button.
   */
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

  /**
   * Builds step progress markup.
   */
  private function buildProgressMarkup(int $step): string {
    $labels = [
      1 => $this->t('Agency'),
      2 => $this->t('Type'),
      3 => $this->t('Adviser'),
      4 => $this->t('Date'),
      5 => $this->t('You'),
      6 => $this->t('Confirm'),
    ];

    $items = [];
    foreach ($labels as $index => $label) {
      $state = $index < $step ? 'done' : ($index === $step ? 'current' : 'todo');
      $items[] = '<span class="booking-step booking-step--' . $state . '">' . $index . '. ' . $label . '</span>';
    }

    return '<div class="booking-progress">' . implode('', $items) . '</div>';
  }

}
