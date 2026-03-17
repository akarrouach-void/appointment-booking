<?php

declare(strict_types=1);

namespace Drupal\appointment\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Provides an interface defining an appointment entity type.
 */
interface AppointmentInterface extends ContentEntityInterface, EntityChangedInterface {

}
