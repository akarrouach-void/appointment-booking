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

  public function checkSlot(Request $request): JsonResponse {
    $adviser_id = (int) $request->query->get('adviser_id', 0);
    $datetime = (string) $request->query->get('datetime', '');
    $exclude_id = (int) $request->query->get('exclude_id', 0);

    if ($adviser_id <= 0 || strlen($datetime) < 16) {
      return new JsonResponse(['available' => FALSE, 'message' => $this->t('Invalid slot selected.')]);
    }

    $date = substr($datetime, 0, 10);
    $time = substr($datetime, 11, 5);

    $slots = $this->wizardHelper->getAvailableHalfHourSlots($adviser_id, $date, $exclude_id);
    
    if (!isset($slots[$time])) {
      return new JsonResponse(['available' => FALSE, 'message' => $this->t('This slot is inactive, outside working hours, or already booked.')]);
    }

    return new JsonResponse(['available' => TRUE]);
  }

  public function getBookedSlots(Request $request): JsonResponse {
    $adviser_id = (int) $request->query->get('adviser_id', 0);
    $start = (string) $request->query->get('start', '');
    $end = (string) $request->query->get('end', '');
    $exclude_id = (int) $request->query->get('exclude_id', 0);

    if ($adviser_id <= 0 || $start === '' || $end === '') {
      return new JsonResponse([]);
    }

    $booked = $this->wizardHelper->getBookedTimesForDateRange($adviser_id, substr($start, 0, 10), substr($end, 0, 10), $exclude_id);
    
    $slots = [];
    foreach ($booked as $datetime) {
      $date = substr($datetime, 0, 10);
      $time = substr($datetime, 11, 5);
      $slots[] = [
        'start' => $date . 'T' . $time . ':00',
        'end' => $date . 'T' . $this->addThirtyMinutes($time) . ':00',
        'allDay' => FALSE,
        'available' => FALSE,
      ];
    }
    
    return new JsonResponse($slots);
  }

  private function addThirtyMinutes(string $time): string {
    [$h, $m] = explode(':', $time);
    $total = (int) $h * 60 + (int) $m + 30;
    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
  }

}