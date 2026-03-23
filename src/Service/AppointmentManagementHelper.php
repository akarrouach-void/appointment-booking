<?php

declare(strict_types=1);

namespace Drupal\appointment\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Shared management utilities for public appointment forms.
 */
final class AppointmentManagementHelper {

  use StringTranslationTrait;

  private const STORE = 'appointment_management';
  private const KEY = 'verified_data';
  // The maximum age of a verification session in seconds (30 minutes).
  private const MAX_AGE = 1800;

  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected MessengerInterface $messenger,
  ) {
  }

  /**
   * Gets the currently verified appointment or NULL.
   */
  public function getVerifiedAppointment(): ?ContentEntityInterface {
    $data = $this->tempStoreFactory->get(self::STORE)->get(self::KEY);

    if (!is_array($data) || empty($data['appointment_id']) || empty($data['verified_at'])) {
      return NULL;
    }

    $expires_at = (int) $data['verified_at'] + self::MAX_AGE;
    if ($expires_at < $this->time->getCurrentTime()) {
      $this->clearVerification();
      return NULL;
    }

    $appointment = $this->entityTypeManager
      ->getStorage('appointment')
      ->load((int) $data['appointment_id']);

    if (!$appointment instanceof ContentEntityInterface) {
      $this->clearVerification();
      return NULL;
    }

    return $appointment;
  }

  /**
   * Stores the verified appointment session data.
   */
  public function setVerifiedAppointment(ContentEntityInterface $appointment, string $normalized_phone): void {
    $this->tempStoreFactory->get(self::STORE)->set(self::KEY, [
      'appointment_id' => (int) $appointment->id(),
      'verified_phone' => $normalized_phone,
      'verified_at' => $this->time->getCurrentTime(),
    ]);
  }

  /**
   * Clears the verification session data.
   */
  public function clearVerification(): void {
    $this->tempStoreFactory->get(self::STORE)->delete(self::KEY);
  }

  /**
   * Normalizes a phone number for safe comparisons.
   */
  public function normalizePhone(string $value): string {
    $normalized = preg_replace('/\D+/', '', $value);
    return $normalized ?? '';
  }

  /**
   * Loads a single appointment by reference code.
   */
  public function loadByReference(string $reference): ?ContentEntityInterface {
    $reference = strtoupper(trim($reference));
    if ($reference === '') {
      return NULL;
    }

    $appointments = $this->entityTypeManager
      ->getStorage('appointment')
      ->loadByProperties(['reference' => $reference]);

    $appointment = reset($appointments);
    return $appointment instanceof ContentEntityInterface ? $appointment : NULL;
  }

  /**
   * Sends an appointment notification email.
   */
  public function sendAppointmentMail(string $key, ContentEntityInterface $appointment): void {
    $to = trim((string) ($appointment->get('customer_email')->value ?? ''));
    if ($to === '') {
      return;
    }

    $params = [
      'appointment' => $appointment,
      'date_label' => $this->formatAppointmentDate($appointment),
      'reference' => (string) ($appointment->get('reference')->value ?? ''),
    ];

    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('appointment', $key, $to, $langcode, $params);

    if (empty($result['result'])) {
      $this->messenger->addWarning($this->t('Appointment updated, but email could not be sent.'));
    }
  }

  /**
   * Returns a translated display label for appointment status.
   */
  public function getStatusLabel(ContentEntityInterface $appointment): string {
    $status = (string) ($appointment->get('appointment_status')->value ?? 'pending');
    return match ($status) {
      'confirmed' => (string) $this->t('Confirmed'),
      'cancelled' => (string) $this->t('Cancelled'),
      default => (string) $this->t('Pending'),
    };
  }

  /**
   * Builds HTML summary block for an appointment.
   */
  public function buildAppointmentSummaryMarkup(ContentEntityInterface $appointment): string {
    $agency = $appointment->get('appointment_agency')->entity;
    $type = $appointment->get('appointment_type')->entity;
    $adviser = $appointment->get('appointment_adviser')->entity;

    $rows = [
      [$this->t('Reference'), (string) ($appointment->get('reference')->value ?? '-')],
      [$this->t('Date'), $this->formatAppointmentDate($appointment)],
      [$this->t('Agency'), $agency ? $agency->label() : '-'],
      [$this->t('Appointment type'), $type ? $type->label() : '-'],
      [$this->t('Adviser'), $adviser ? $adviser->label() : '-'],
      [$this->t('Status'), $this->getStatusLabel($appointment)],
    ];

    $items = '';
    foreach ($rows as [$label, $value]) {
      $items .= '<div class="booking-summary-row">'
        . '<div class="booking-summary-label">' . Html::escape((string) $label) . '</div>'
        . '<div class="booking-summary-value">' . Html::escape((string) $value) . '</div>'
        . '</div>';
    }

    return '<div class="booking-summary">' . $items . '</div>';
  }

  /**
   * Formats appointment date value for display.
   */
  public function formatAppointmentDate(ContentEntityInterface $appointment): string {
    $value = (string) ($appointment->get('appointment_date')->value ?? '');
    if ($value === '') {
      return '-';
    }

    $date = new DrupalDateTime($value);
    if ($date->hasErrors()) {
      return $value;
    }

    return $date->format('d/m/Y H:i');
  }

}