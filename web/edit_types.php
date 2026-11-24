<?php
declare(strict_types=1);
namespace MRBS;

use MRBS\Form\Form;

// The Booking Types page, where admins can add booking types and change their names,
// colours and order, make types inactive and delete unused types.  The changes are
// handled by edit_types_handler.php and stored in the file given by $booking_types_file.
// See BookingTypes.php.

require "defaultincludes.inc";


/**
 * Get the number of bookings, including series, that use each booking type.
 *
 * @return array<string, int>
 */
function get_type_counts() : array
{
  $result = array();

  foreach (array('entry', 'repeat') as $table)
  {
    $res = db()->query("SELECT type, COUNT(*) AS n FROM " . _tbl($table) . " GROUP BY type");
    while (false !== ($row = $res->next_row_keyed()))
    {
      $result[$row['type']] = ($result[$row['type']] ?? 0) + (int) $row['n'];
    }
  }

  return $result;
}


/**
 * Get the HTML for a button that posts an action for a booking type to the handler.
 */
function get_type_button(string $action, string $code, string $label, string $title='', string $confirm='') : string
{
  $html = '<form method="post" action="' . escape_html(multisite('edit_types_handler.php')) . '"';
  if ($confirm !== '')
  {
    $html .= ' data-confirm="' . escape_html($confirm) . '"';
  }
  $html .= '>' . Form::getTokenHTML();
  $html .= '<input type="hidden" name="action" value="' . escape_html($action) . '">';
  $html .= '<input type="hidden" name="code" value="' . escape_html($code) . '">';
  $html .= '<input type="submit" value="' . escape_html($label) . '"';
  if ($title !== '')
  {
    $html .= ' title="' . escape_html($title) . '" aria-label="' . escape_html($title) . '"';
  }
  $html .= '></form>';

  return $html;
}


checkAuthorised(this_page());

$error = get_form_var('error', 'string');
$message = get_form_var('message', 'string');
$code = get_form_var('code', 'string');

$context = array(
    'view'      => $view,
    'view_all'  => $view_all,
    'year'      => $year,
    'month'     => $month,
    'day'       => $day,
    'area'      => $area ?? null,
    'room'      => $room ?? null
  );

print_header($context);

echo "<div id=\"booking_types\">\n";
echo '<h2>' . escape_html(get_vocab('booking_types')) . "</h2>\n";

if (!BookingTypes::isEnabled())
{
  echo '<p>' . escape_html(get_vocab('booking_types_not_enabled')) . "</p>\n";
  echo "</div>\n";
  print_footer();
  exit;
}

$data = BookingTypes::getData();
$counts = get_type_counts();

// Show the result of the last action.  Only show our own messages.
foreach (array('error' => $error, 'message' => $message) as $class => $tag)
{
  if (isset($tag) && (strpos($tag, 'booking_type') === 0))
  {
    $type_name = $code;
    foreach ($data['types'] as $type)
    {
      if ($type['code'] === $code)
      {
        $type_name = $type['name'];
      }
    }
    echo "<p class=\"$class\">" .
         escape_html(get_vocab($tag, $type_name ?? '', basename(BookingTypes::getFile()))) .
         "</p>\n";
  }
}

$file = BookingTypes::getFile();

if (BookingTypes::load() === null)
{
  if (is_file($file))
  {
    echo '<p class="error">' . escape_html(get_vocab('booking_types_file_invalid', $file)) . "</p>\n";
  }
  echo '<p class="note">' . escape_html(get_vocab('booking_types_from_config')) . "</p>\n";
}
if (is_file($file) ? !is_writable($file) : !is_writable(dirname($file)))
{
  echo '<p class="error">' . escape_html(get_vocab('booking_types_not_writable', $file)) . "</p>\n";
}

echo '<p>' . escape_html(get_vocab('booking_types_explanation')) . "</p>\n";

// The table of booking types
$action = escape_html(multisite('edit_types_handler.php'));
$token = Form::getTokenHTML();
$n = count($data['types']);

echo "<table class=\"admin_table\" id=\"booking_types_table\">\n";
echo '<thead><tr>';
echo '<th>' . escape_html(get_vocab('booking_type_order')) . '</th>';
echo '<th>' . escape_html(get_vocab('booking_type_code')) . '</th>';
echo '<th>' . escape_html(get_vocab('name')) . '</th>';
echo '<th>' . escape_html(get_vocab('booking_type_color')) . '</th>';
echo '<th>' . escape_html(get_vocab('booking_type_active')) . '</th>';
echo '<th></th>';
echo '<th>' . escape_html(get_vocab('booking_type_uses')) . '</th>';
echo '<th></th>';
echo "</tr></thead>\n<tbody>\n";

foreach ($data['types'] as $i => $type)
{
  $type_code = $type['code'];
  $uses = $counts[$type_code] ?? 0;
  $form_id = "booking_type_$type_code";

  echo "<tr>";

  echo '<td class="order">';
  if ($i > 0)
  {
    echo get_type_button('up', $type_code, '▲', get_vocab('booking_type_move_up'));
  }
  if ($i < $n - 1)
  {
    echo get_type_button('down', $type_code, '▼', get_vocab('booking_type_move_down'));
  }
  echo '</td>';

  echo '<td class="code">' . escape_html($type_code) . '</td>';

  // The editable fields belong to the row's form (using the form attribute), because a
  // form can't span table cells
  echo "<td><input type=\"text\" form=\"$form_id\" name=\"name\" value=\"" . escape_html($type['name']) . '"' .
       ' maxlength="' . BookingTypes::MAX_NAME_LENGTH . '" required' .
       ' aria-label="' . escape_html(get_vocab('name')) . '"></td>';
  echo "<td><input type=\"color\" form=\"$form_id\" name=\"color\" value=\"" . escape_html($type['color']) . '"' .
       ' aria-label="' . escape_html(get_vocab('booking_type_color')) . '"></td>';
  echo "<td class=\"active\"><input type=\"checkbox\" form=\"$form_id\" name=\"active\" value=\"1\"" .
       (($type['active']) ? ' checked' : '') .
       ' aria-label="' . escape_html(get_vocab('booking_type_active')) . '"></td>';
  echo "<td><form id=\"$form_id\" method=\"post\" action=\"$action\">$token" .
       '<input type="hidden" name="action" value="save">' .
       '<input type="hidden" name="code" value="' . escape_html($type_code) . '">' .
       '<input type="submit" value="' . escape_html(get_vocab('save')) . '"></form></td>';

  echo '<td class="uses">' . escape_html((string) $uses) . '</td>';

  echo '<td>';
  if ($uses == 0)
  {
    echo get_type_button('delete', $type_code, get_vocab('delete'), '',
                         get_vocab('booking_type_delete_confirm', $type['name']));
  }
  echo '</td>';

  echo "</tr>\n";
}

echo "</tbody>\n</table>\n";

// The form for adding a booking type.  Don't offer letters that are already used in
// either case (see edit_types_handler.php).
$used = array_map('strtoupper', array_column($data['types'], 'code'));
$free = array_values(array_diff(range('A', 'Z'), $used));

echo '<h3>' . escape_html(get_vocab('booking_type_add')) . "</h3>\n";

if (empty($free))
{
  echo '<p>' . escape_html(get_vocab('booking_type_no_free_codes')) . "</p>\n";
}
else
{
  echo "<form id=\"add_booking_type\" method=\"post\" action=\"$action\">$token\n";
  echo "<input type=\"hidden\" name=\"action\" value=\"add\">\n";
  echo '<label>' . escape_html(get_vocab('name')) . ' <input type="text" name="name" maxlength="' .
       BookingTypes::MAX_NAME_LENGTH . "\" required></label>\n";
  echo '<label>' . escape_html(get_vocab('booking_type_code')) . ' <select name="code">';
  foreach ($free as $letter)
  {
    echo "<option value=\"$letter\">$letter</option>";
  }
  echo "</select></label>\n";
  echo '<label>' . escape_html(get_vocab('booking_type_color')) . " <input type=\"color\" name=\"color\" value=\"#ffcc99\"></label>\n";
  echo '<input type="submit" value="' . escape_html(get_vocab('booking_type_add')) . "\">\n";
  echo "</form>\n";
  echo '<p class="note">' . escape_html(get_vocab('booking_type_code_note')) . "</p>\n";
}

echo "</div>\n";

print_footer();
