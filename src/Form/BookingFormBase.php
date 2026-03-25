<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\appointment\Service\AppointmentWizardHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared base for the booking and modify wizard forms.
 */
abstract class BookingFormBase extends FormBase {

  protected const TOTAL_STEPS = 6;

  /**
   * The tempstore key used by the concrete form — each subclass defines its own.
   */
  abstract protected function storeKey(): string;

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

  public function ajaxRefresh(array &$form): array {
    return $form;
  }

  public function nextSubmit(array &$form, FormStateInterface $form_state): void {
    $step = $this->currentStep($form_state);
    $data = $this->loadWizardData();
    $this->storeStepData($step, $form_state, $data);
    $this->saveWizardData($data);

    $form_state->set('step', min(static::TOTAL_STEPS, $step + 1));
    $form_state->setRebuild(TRUE);
  }

  public function backSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', max(1, $this->currentStep($form_state) - 1));
    $form_state->setRebuild(TRUE);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $step = $this->currentStep($form_state);

    $simple = [
      1 => ['agency',           $this->t('Please choose an agency.')],
      2 => ['appointment_type', $this->t('Please choose an appointment type.')],
      3 => ['adviser',          $this->t('Please choose an adviser.')],
    ];

    
    if (isset($simple[$step]) && !$this->value($form_state, $simple[$step][0])) {
      $form_state->setErrorByName('wizard][' . $simple[$step][0], $simple[$step][1]);
      return;
    }

    if ($step === 4) {
      $this->validateStepDate($form_state);
    }

    if ($step === 5) {
      foreach ([
        'customer_name'  => $this->t('Name is required.'),
        'customer_email' => $this->t('Email is required.'),
        'customer_phone' => $this->t('Phone is required.'),
      ] as $field => $message) {
        if (!$this->value($form_state, $field)) {
          $form_state->setErrorByName('wizard][' . $field, $message);
        }
      }
    }
  }

  /**
   * Validates step 4 (date/time). Subclasses may override to add exclude logic.
   */
  protected function validateStepDate(FormStateInterface $form_state): void {
    $date = (string) ($this->value($form_state, 'appointment_date') ?? '');
    if ($date === '' || strlen($date) < 16) {
      $form_state->setErrorByName('wizard][appointment_date', $this->t('Please select a time slot on the calendar.'));
      return;
    }
    $day  = substr($date, 0, 10);
    $time = substr($date, 11, 5);
    $data = $this->loadWizardData();
    $slots = $this->wizardHelper->getAvailableHalfHourSlots((int) ($data['adviser'] ?? 0), $day);
    if (!isset($slots[$time])) {
      $form_state->setErrorByName('wizard][appointment_date', $this->t('Selected slot is no longer available.'));
    }
  }

  protected function buildStepAgency(array &$container, array $data): void {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('agency')->loadMultiple() as $agency) {
      $options[$agency->id()] = $agency->label();
    }
    $container['title']['#markup'] = '<h3>1. ' . $this->t('Choose an agency') . '</h3>';
    $container['agency'] = [
      '#type'          => 'radios',
      '#options'       => $options,
      '#default_value' => $data['agency'] ?? NULL,
      '#required'      => TRUE,
    ];
  }

  protected function buildStepType(array &$container, array $data): void {
    $container['title']['#markup'] = '<h3>2. ' . $this->t('Choose appointment type') . '</h3>';
    $options = $this->wizardHelper->getTypeOptionsForAgency((int) ($data['agency'] ?? 0));
    if (empty($options)) {
      $container['empty']['#markup'] = '<p>' . $this->t('No appointment type is configured for this agency.') . '</p>';
      return;
    }
    $container['appointment_type'] = [
      '#type'          => 'radios',
      '#options'       => $options,
      '#default_value' => $data['appointment_type'] ?? NULL,
      '#required'      => TRUE,
    ];
  }

  protected function buildStepAdviser(array &$container, array $data): void {
    $container['title']['#markup'] = '<h3>3. ' . $this->t('Choose adviser') . '</h3>';
    $options = $this->wizardHelper->getAdviserOptions(
      (int) ($data['agency'] ?? 0),
      (int) ($data['appointment_type'] ?? 0),
    );
    if (empty($options)) {
      $container['empty']['#markup'] = '<p>' . $this->t('No adviser matches your agency and type.') . '</p>';
      return;
    }
    $container['adviser'] = [
      '#type'          => 'radios',
      '#options'       => $options,
      '#default_value' => $data['adviser'] ?? NULL,
      '#required'      => TRUE,
    ];
  }

  /**
   * Builds the calendar step.
   *
   * @param int $excludeAppointmentId Appointment ID whose slot should stay
   *   selectable (0 = none, used in new-booking flow).
   */
  protected function buildStepDate(array &$container, array $data, int $excludeAppointmentId = 0): void {
    $container['title']['#markup'] = '<h3>4. ' . $this->t('Choose date and time') . '</h3>';

    $container['calendar_wrapper'] = [
      '#type'       => 'container',
      '#attributes' => [
        'id'               => 'appointment-calendar',
        'data-adviser'     => (int) ($data['adviser'] ?? 0),
        'data-slots-url'   => '/appointment/slots',
        'data-exclude-id'  => $excludeAppointmentId,
      ],
    ];

    $container['appointment_date'] = [
      '#type'          => 'hidden',
      '#attributes'    => ['id' => 'appointment-selected-date'],
      '#default_value' => (string) ($data['appointment_date'] ?? ''),
    ];
  }

  protected function buildStepCustomer(array &$container, array $data): void {
    $container['title']['#markup'] = '<h3>5. ' . $this->t('Your information') . '</h3>';
    $container['customer_name']  = ['#type' => 'textfield', '#title' => $this->t('Full name'), '#default_value' => $data['customer_name']  ?? '', '#required' => TRUE];
    $container['customer_email'] = ['#type' => 'email',     '#title' => $this->t('Email'),     '#default_value' => $data['customer_email'] ?? '', '#required' => TRUE];
    $container['customer_phone'] = ['#type' => 'tel',       '#title' => $this->t('Phone'),     '#default_value' => $data['customer_phone'] ?? '', '#required' => TRUE];
    $container['notes']          = ['#type' => 'textarea',  '#title' => $this->t('Notes'),     '#default_value' => $data['notes']          ?? ''];
  }

  protected function loadWizardData(): array {
    return $this->tempStoreFactory->get('appointment_booking')->get($this->storeKey()) ?? [];
  }

  protected function saveWizardData(array $data): void {
    $this->tempStoreFactory->get('appointment_booking')->set($this->storeKey(), $data);
  }

  protected function clearWizardData(): void {
    $this->tempStoreFactory->get('appointment_booking')->delete($this->storeKey());
  }

  protected function storeStepData(int $step, FormStateInterface $form_state, array &$data): void {
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
      $data['appointment_day']  = strlen($date) >= 10 ? substr($date, 0, 10) : '';
      $data['appointment_time'] = strlen($date) >= 16 ? substr($date, 11, 5) : '';
      return;
    }
    if ($step === 5) {
      $data['customer_name']  = (string) ($this->value($form_state, 'customer_name')  ?? '');
      $data['customer_email'] = (string) ($this->value($form_state, 'customer_email') ?? '');
      $data['customer_phone'] = (string) ($this->value($form_state, 'customer_phone') ?? '');
      $data['notes']          = (string) ($this->value($form_state, 'notes')          ?? '');
    }
  }

  protected function value(FormStateInterface $form_state, string $key): mixed {
    return $form_state->getValue(['wizard', $key]) ?? $form_state->getValue($key);
  }

  protected function currentStep(FormStateInterface $form_state): int {
    $step = max(1, min(static::TOTAL_STEPS, (int) ($form_state->get('step') ?? 1)));
    $form_state->set('step', $step);
    return $step;
  }

  protected function currentStepValidationScope(int $step): array {
    return match ($step) {
      1 => [['wizard', 'agency']],
      2 => [['wizard', 'appointment_type']],
      3 => [['wizard', 'adviser']],
      4 => [['wizard', 'appointment_date']],
      5 => [['wizard', 'customer_name'], ['wizard', 'customer_email'], ['wizard', 'customer_phone']],
      default => [],
    };
  }

  /**
   * Adds the standard wizard scaffold to $form and returns the wizard container.
   */
  protected function initWizardScaffold(array &$form, int $step): void {
    $form['#tree']   = TRUE;
    $form['#prefix'] = '<div id="booking-form-wrapper">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'appointment/booking_wizard';
    if ($step === 4) {
      $form['#attached']['library'][] = 'appointment/booking_calendar';
    }
    $form['progress']['#markup'] = $this->wizardHelper->buildProgressMarkup($step);
    $form['wizard'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['booking-wizard']],
    ];
  }

  /**
   * Adds Back / Next / Submit action buttons to $form.
   *
   * @param array $extra Extra buttons to prepend (e.g. "Cancel edit").
   */
  protected function addActions(array &$form, int $step, string $submitLabel = '', array $extra = []): void {
    $form['actions'] = ['#type' => 'actions'];

    foreach ($extra as $key => $button) {
      $form['actions'][$key] = $button;
    }

    if ($step > 1) {
      $form['actions']['back'] = [
        '#type'                    => 'submit',
        '#value'                   => $this->t('Back'),
        '#submit'                  => ['::backSubmit'],
        '#limit_validation_errors' => [],
        '#ajax'                    => ['callback' => '::ajaxRefresh', 'wrapper' => 'booking-form-wrapper'],
      ];
    }

    if ($step < static::TOTAL_STEPS) {
      $form['actions']['next'] = [
        '#type'                    => 'submit',
        '#value'                   => $this->t('Next'),
        '#submit'                  => ['::nextSubmit'],
        '#limit_validation_errors' => $this->currentStepValidationScope($step),
        '#ajax'                    => ['callback' => '::ajaxRefresh', 'wrapper' => 'booking-form-wrapper'],
      ];
    }
    else {
      $form['actions']['submit'] = [
        '#type'  => 'submit',
        '#value' => $submitLabel !== '' ? $submitLabel : (string) $this->t('Submit'),
      ];
    }
  }

}