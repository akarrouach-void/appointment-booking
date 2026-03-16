# Appointment Booking Module

A custom Drupal 11 module for booking appointments with advisers at agencies.

## Requirements

- Drupal 11
- [Profile](https://www.drupal.org/project/profile)
- [Office Hours](https://www.drupal.org/project/office_hours)
- [Token](https://www.drupal.org/project/token)
- [Pathauto](https://www.drupal.org/project/pathauto)

## Installation

```bash
composer require drupal/profile drupal/office_hours drupal/token drupal/pathauto
drush en appointment -y
drush cr
```

---

## Taxonomy — Appointment Type

Created a taxonomy vocabulary called `appointment_type` through the backoffice at `Structure → Taxonomy → Add Vocabulary`. This vocabulary serves two purposes: it categorises appointments by type, and it is used as the specializations field on the Adviser profile to match users with the right adviser during booking.

The vocabulary ships with the module via `config/install/taxonomy.vocabulary.appointment_type.yml`. Terms are not shipped — they are created by the site administrator.

![Appointment Type terms](images/appointment_type.png)

---

## Adviser Role

Created a custom role called `Adviser` at `People → Roles → Add Role`. This role is assigned to users who act as advisers in the system.

The role ships with the module via:

```
config/install/user.role.adviser.yml
config/install/system.action.user_add_role_action.adviser.yml
config/install/system.action.user_remove_role_action.adviser.yml
```

---

## Adviser Profile

Installed the [Profile](https://www.drupal.org/project/profile) module and created a profile type called `Adviser` restricted to users with the `Adviser` role. This means only advisers will have this profile — not regular users or admins.

```bash
composer require drupal/profile
drush en profile -y
```

The profile type ships with the module via:

```
config/install/profile.type.adviser.yml
config/install/system.action.profile_delete_action.yml
config/install/system.action.profile_publish_action.yml
config/install/system.action.profile_unpublish_action.yml
config/install/views.view.profiles.yml
```

![Adviser profile type restricted to Adviser role](images/adviser_profile.png)

### Specializations field

An entity reference field pointing to the `appointment_type` taxonomy with unlimited values. An adviser can cover multiple appointment types. When a user books an appointment, the system filters advisers by both agency and specialization to show only the relevant advisers.

### Working Hours field

Powered by the [Office Hours](https://www.drupal.org/project/office_hours) module. Stores the adviser's weekly availability as a recurring pattern — not specific dates. The default value is Monday to Friday, 09:00 to 17:00.

```bash
composer require drupal/office_hours
drush en office_hours -y
```

At booking time, the system reads the adviser's working hours to generate available time slots, then subtracts any already booked appointments for that specific date to show only free slots.

```
Available slots = Working hours slots − Already booked appointments (status ≠ cancelled)
```

![Working Hours default configuration](images/working_hours.png)

The field is stored in the database as integers:

```
profile__field_working_hours
entity_id | day | starthours | endhours
----------|-----|------------|----------
7         |  1  |    900     |   1700    ← Monday
7         |  2  |    900     |   1700    ← Tuesday
...
```

Day numbers follow PHP conventions: 0 = Sunday, 1 = Monday ... 6 = Saturday. Days with no rows are treated as closed.
