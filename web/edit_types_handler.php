<?php
declare(strict_types=1);
namespace MRBS;

use MRBS\Form\Form;

// Handles the forms on the Booking Types page (edit_types.php).  Admins only.

require "defaultincludes.inc";


/**
 * Go back to the Booking Types page, showing a message.
 */
function return_to_types_page(string $message, ?string $code=null, bool $is_error=true) : void
{
  $params = array(($is_error) ? 'error' : 'message' => $message);
  if (isset($code))
  {
    $params['code'] = $code;
  }
  location_header(multisite('edit_types.php?' . http_build_query($params, '', '&')));
}


/**
 * Get the number of bookings, including series, that use a booking type.
 */
function count_type_uses(string $code) : int
{
  $n = db()->query_scalar_non_bool("SELECT COUNT(*) FROM " . _tbl('entry') . " WHERE type=?", array($code));
  $n += db()->query_scalar_non_bool("SELECT COUNT(*) FROM " . _tbl('repeat') . " WHERE type=?", array($code));
  return (int) $n;
}


function count_other_active_types(array $types, int $index) : int
{
  $n = 0;
  foreach ($types as $i => $type)
  {
    if (($i !== $index) && $type['active'])
    {
      $n++;
    }
  }
  return $n;
}


checkAuthorised(this_page());
Form::checkToken();

if (!BookingTypes::isEnabled())
{
  return_to_types_page('booking_types_not_enabled');
}

$action = get_form_var('action', 'string');
$code = trim((string) get_form_var('code', 'string'));
$name = trim((string) get_form_var('name', 'string'));
$color = (string) get_form_var('color', 'string');

$data = BookingTypes::getData();
$types = $data['types'];

// Find the type
$index = null;
foreach ($types as $i => $type)
{
  if ($type['code'] === $code)
  {
    $index = $i;
    break;
  }
}

switch ($action)
{
  case 'add':
    // New types get upper case letters.  Don't allow a letter that's already used in either
    // case, because the database comparison may be case-insensitive.
    if (!BookingTypes::isValidCode($code) || ($code !== strtoupper($code)))
    {
      return_to_types_page('booking_type_invalid_code');
    }
    if (in_array($code, array_map('strtoupper', array_column($types, 'code')), true))
    {
      return_to_types_page('booking_type_code_exists', $code);
    }
    if (null !== ($error = BookingTypes::checkName($name)))
    {
      return_to_types_page($error);
    }
    if (!BookingTypes::isValidColor($color))
    {
      return_to_types_page('booking_type_invalid_color');
    }
    $types[] = array('code' => $code, 'name' => $name, 'color' => strtolower($color), 'active' => true);
    $message = 'booking_type_added';
    break;

  case 'save':
    if (!isset($index))
    {
      return_to_types_page('booking_type_not_found', $code);
    }
    if (null !== ($error = BookingTypes::checkName($name)))
    {
      return_to_types_page($error, $code);
    }
    if (!BookingTypes::isValidColor($color))
    {
      return_to_types_page('booking_type_invalid_color', $code);
    }
    $active = (get_form_var('active', 'string') !== null);
    if (!$active && (count_other_active_types($types, $index) === 0))
    {
      return_to_types_page('booking_type_last_active');
    }
    $types[$index]['name'] = $name;
    $types[$index]['color'] = strtolower($color);
    $types[$index]['active'] = $active;
    $message = 'booking_type_saved';
    break;

  case 'delete':
    if (!isset($index))
    {
      return_to_types_page('booking_type_not_found', $code);
    }
    if (count_type_uses($code) > 0)
    {
      return_to_types_page('booking_type_in_use', $code);
    }
    if (count_other_active_types($types, $index) === 0)
    {
      return_to_types_page('booking_type_last_active');
    }
    array_splice($types, $index, 1);
    $message = 'booking_type_deleted';
    break;

  case 'up':
  case 'down':
    if (!isset($index))
    {
      return_to_types_page('booking_type_not_found', $code);
    }
    $other = ($action == 'up') ? $index - 1 : $index + 1;
    if (isset($types[$other]))
    {
      [$types[$index], $types[$other]] = [$types[$other], $types[$index]];
    }
    $message = null;
    break;

  default:
    throw new \Exception("Unknown action '$action'");
    break;
}

if (!BookingTypes::save(array('types' => array_values($types))))
{
  return_to_types_page('booking_types_save_failed');
}

if (isset($message))
{
  return_to_types_page($message, $code, false);
}
else
{
  location_header(multisite('edit_types.php'));
}
