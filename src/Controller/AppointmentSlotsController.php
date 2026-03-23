<?php

declare(strict_types=1);

namespace Drupal\appointment\Controller;

use Drupal\appointment\Service\AppointmentWizardHelper;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AppointmentSlotsController extends ControllerBase {

  public function __construct(
    protected AppointmentWizardHelper $wizardHelper,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('appointment.wizard_helper'));
  }

  public function getSlots(Request $request): JsonResponse {
    $adviser_id = (int) $request->query->get('adviser_id', 0);
    $date = (string) $request->query->get('date', '');
    $exclude_id = (int) $request->query->get('exclude_id', 0);

    if ($adviser_id <= 0 || $date === '') {
      return new JsonResponse([]);
    }

    // Validate strict date format.
    $date_object = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$date_object || $date_object->format('Y-m-d') !== $date) {
      return new JsonResponse([]);
    }

    $available = $this->wizardHelper->getAvailableHalfHourSlots($adviser_id, $date, $exclude_id);
    $booked = $this->wizardHelper->getBookedTimesForDate($adviser_id, $date, $exclude_id);

    $slots = [];

    foreach ($available as $time) {
      $slots[] = [
        'start' => $date . 'T' . $time . ':00',
        'end' => $date . 'T' . $this->addThirtyMinutes($time) . ':00',
        'allDay' => FALSE,
        'available' => TRUE,
      ];
    }

    foreach ($booked as $time) {
      $slots[] = [
        'start' => $date . 'T' . $time . ':00',
        'end' => $date . 'T' . $this->addThirtyMinutes($time) . ':00',
        'allDay' => FALSE,
        'available' => FALSE,
      ];
    }

    usort($slots, static fn(array $a, array $b): int => strcmp((string) $a['start'], (string) $b['start']));

    return new JsonResponse($slots);
  }

  private function addThirtyMinutes(string $time): string {
    [$h, $m] = explode(':', $time);
    $total = (int) $h * 60 + (int) $m + 30;
    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
  }

}