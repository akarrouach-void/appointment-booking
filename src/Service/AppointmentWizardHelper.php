<?php

declare(strict_types=1);

namespace Drupal\appointment\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Shared utilities for the appointment booking and modify wizard forms.
 */
final class AppointmentWizardHelper {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  // ---------------------------------------------------------------------------
  // Slot helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns available 30-minute slots for an adviser on a given date.
   *
   * @param int $adviser_id
   * @param string $date YYYY-MM-DD
   * @param int $exclude_appointment_id Appointment ID to exclude from booked check (0 = none).
   */
  public function getAvailableHalfHourSlots(int $adviser_id, string $date, int $exclude_appointment_id = 0): array {
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
      $start = (int) ($row['starthours'] ?? 0);
      $end = (int) ($row['endhours'] ?? 0);
      if ((int) ($row['day'] ?? -1) !== $weekday || $start <= 0 || $end <= $start) {
        continue;
      }
      $start_m = intdiv($start, 100) * 60 + $start % 100;
      $end_m = intdiv($end, 100) * 60 + $end % 100;
      for ($m = $start_m; $m + 30 <= $end_m; $m += 30) {
        $t = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        $slots[$t] = $t;
      }
    }

    if (empty($slots)) {
      return [];
    }

    foreach ($this->getBookedTimesForDate($adviser_id, $date, $exclude_appointment_id) as $time) {
      unset($slots[$time]);
    }

    return $slots;
  }

  /**
   * Returns booked HH:MM times for an adviser on a date, skipping cancelled.
   */
  public function getBookedTimesForDate(int $adviser_id, string $date, int $exclude_appointment_id = 0): array {
    $date_obj = date_create_immutable($date);
    if (!$date_obj) {
      return [];
    }

    $query = $this->entityTypeManager->getStorage('appointment')->getQuery()
      ->accessCheck(FALSE)
      ->condition('appointment_adviser', $adviser_id)
      ->condition('appointment_date', $date_obj->format('Y-m-d') . 'T00:00:00', '>=')
      ->condition('appointment_date', $date_obj->modify('+1 day')->format('Y-m-d') . 'T00:00:00', '<');

    if ($exclude_appointment_id > 0) {
      $query->condition('id', $exclude_appointment_id, '<>');
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    $booked = [];
    foreach ($this->entityTypeManager->getStorage('appointment')->loadMultiple($ids) as $appointment) {
      if (!$appointment instanceof ContentEntityInterface || !$appointment->hasField('appointment_date')) {
        continue;
      }
      if ($appointment->hasField('appointment_status') && (string) ($appointment->get('appointment_status')->value ?? '') === 'cancelled') {
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
   * Returns the adviser profile entity for a given user ID.
   */
  public function getAdviserProfile(int $adviser_id): ?ContentEntityInterface {
    $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties(['uid' => $adviser_id, 'type' => 'adviser']);
    $profile = reset($profiles);
    return $profile instanceof ContentEntityInterface ? $profile : NULL;
  }

  // ---------------------------------------------------------------------------
  // Entity option helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns appointment type options for the selected agency.
   */
  public function getTypeOptionsForAgency(int $agency_id): array {
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
    $options = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($term_ids) as $term) {
      $options[$term->id()] = $term->label();
    }
    return $options;
  }

  /**
   * Returns adviser options filtered by agency and specialization.
   */
  public function getAdviserOptions(int $agency_id, int $type_id): array {
    if ($agency_id <= 0 || $type_id <= 0) {
      return [];
    }
    $profile_ids = $this->entityTypeManager->getStorage('profile')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'adviser')
      ->condition('field_agency.target_id', $agency_id)
      ->condition('field_specializations.target_id', $type_id)
      ->execute();

    if (empty($profile_ids)) {
      return [];
    }

    $options = [];
    foreach ($this->entityTypeManager->getStorage('profile')->loadMultiple($profile_ids) as $profile) {
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

  // ---------------------------------------------------------------------------
  // Mail helpers
  // ---------------------------------------------------------------------------

  /**
   * Sends an appointment notification email.
   */
  public function sendAppointmentMail(string $key, ContentEntityInterface $appointment): void {
    $to = trim((string) ($appointment->get('customer_email')->value ?? ''));
    if ($to === '') {
      return;
    }
    $result = \Drupal::service('plugin.manager.mail')->mail(
      'appointment',
      $key,
      $to,
      \Drupal::languageManager()->getDefaultLanguage()->getId(),
      [
        'appointment' => $appointment,
        'reference' => (string) ($appointment->get('reference')->value ?? ''),
        'date_label' => $this->formatAppointmentDateLabel($appointment),
      ],
    );
    if (empty($result['result'])) {
      \Drupal::messenger()->addWarning($this->t('Appointment updated, but email could not be sent.'));
    }
  }

  /**
   * Formats the appointment date for use in email payloads.
   */
  public function formatAppointmentDateLabel(ContentEntityInterface $appointment): string {
    $value = (string) ($appointment->get('appointment_date')->value ?? '');
    if ($value === '') {
      return '-';
    }
    $date = new DrupalDateTime($value);
    return $date->hasErrors() ? $value : $date->format('d/m/Y H:i');
  }

  // ---------------------------------------------------------------------------
  // UI helpers
  // ---------------------------------------------------------------------------

  /**
   * Builds the step progress bar markup.
   */
  public function buildProgressMarkup(int $step, int $total_steps = 6): string {
    $labels = [
      1 => $this->t('Agency'),
      2 => $this->t('Type'),
      3 => $this->t('Adviser'),
      4 => $this->t('Date'),
      5 => $this->t('You'),
      6 => $this->t('Confirm'),
    ];
    $items = array_map(
      fn($i, $label) => '<span class="booking-step booking-step--' . ($i < $step ? 'done' : ($i === $step ? 'current' : 'todo')) . '">' . $i . '. ' . $label . '</span>',
      array_keys($labels),
      $labels,
    );
    return '<div class="booking-progress">' . implode('', $items) . '</div>';
  }

  /**
   * Renders a booking summary table as HTML markup.
   *
   * @param array $rows Array of [label, value] pairs.
   */
  public function buildSummaryMarkup(array $rows): string {
    $items = implode('', array_map(
      fn($row) => '<div class="booking-summary-row">'
        . '<div class="booking-summary-label">' . Html::escape((string) $row[0]) . '</div>'
        . '<div class="booking-summary-value">' . Html::escape((string) $row[1]) . '</div>'
        . '</div>',
      $rows,
    ));
    return '<div class="booking-summary">'
      . '<p class="booking-summary-intro">' . $this->t('Please verify all information before confirming your appointment.') . '</p>'
      . $items . '</div>';
  }

}