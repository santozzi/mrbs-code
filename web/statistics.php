<?php
declare(strict_types=1);
namespace MRBS;

use DateTimeImmutable;
use DateTimeZone;
use MRBS\Intl\IntlDateFormatter;

/*
 * Room occupancy statistics: how much the rooms are used, when, and by whom.
 *
 * This page is not listed in get_page_level() and so is restricted to admins.
 * The charts are generated on the server as plain HTML and inline SVG, so no
 * charting library is needed.  Areas that use periods are not included, because
 * their bookings don't have meaningful start and end times.
 */

require "defaultincludes.inc";

checkAuthorised(this_page());


/**
 * Get the first key of an array, or null if it is empty.  (array_key_first() needs PHP 7.3.)
 */
function stats_first_key(array $array)
{
  foreach ($array as $key => $value)
  {
    return $key;
  }

  return null;
}


/**
 * Format a number in the current locale with a fixed number of decimal places.
 */
function stats_number(float $n, int $decimals=0) : string
{
  static $formatters = array();

  if (!isset($formatters[$decimals]))
  {
    $formatter = new \NumberFormatter(Language::getInstance()->getWebLocale(), \NumberFormatter::DECIMAL);
    $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
    $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
    $formatters[$decimals] = $formatter;
  }

  // Round first in case we're using the emulated NumberFormatter, which ignores the attributes
  return $formatters[$decimals]->format(round($n, $decimals));
}


/**
 * Format a fraction (eg 0.25) as a percentage, using one decimal place for small values.
 */
function stats_percent(float $fraction) : string
{
  $decimals = (($fraction > 0) && ($fraction < 0.1)) ? 1 : 0;
  return get_vocab('stats_percent', stats_number($fraction * 100, $decimals));
}


function stats_hours(float $hours, int $decimals=0) : string
{
  return get_vocab('stats_hours_short', stats_number($hours, $decimals));
}


/**
 * Get the name of a booking type, falling back to the type code.
 */
function stats_type_name(string $type) : string
{
  $name = get_type_vocab($type);
  return ($name === "type.$type") ? $type : $name;
}


/**
 * Stop spreadsheet programs from interpreting a cell as a formula.
 */
function stats_csv_value($value) : string
{
  if (is_int($value) || is_float($value))
  {
    return (string) $value;
  }

  $value = (string) $value;

  if (($value !== '') && (strpbrk($value[0], "=+-@\t\r") !== false))
  {
    $value = "'" . $value;
  }

  return $value;
}


/**
 * Output a horizontal bar chart.
 *
 * @param array<array{0: string, 1: float, 2: string, 3: string}> $rows Each row is
 *          [label, value, value as text, tooltip]
 */
function stats_bar_chart(array $rows, string $aria_label) : void
{
  if (empty($rows))
  {
    echo '<p class="stats_empty">' . escape_html(get_vocab('stats_no_data')) . "</p>\n";
    return;
  }

  $max = max(array_column($rows, 1)) ?: 1;

  echo '<div class="stats_bars" role="img" aria-label="' . escape_html($aria_label) . "\">\n";
  foreach ($rows as [$label, $value, $text, $tooltip])
  {
    $width = max(0.5, 100 * $value / $max);
    echo '<div class="stats_bar_row" data-tooltip="' . escape_html($tooltip) . '">';
    echo '<span class="stats_bar_label" title="' . escape_html($label) . '">' . escape_html($label) . '</span>';
    echo '<span class="stats_bar_track"><span class="stats_bar" style="width:' . round($width, 2) . '%"></span></span>';
    echo '<span class="stats_bar_value">' . escape_html($text) . '</span>';
    echo "</div>\n";
  }
  echo "</div>\n";
}


/**
 * Output a vertical column chart as inline SVG.
 *
 * @param array<string, float>  $series   key => value
 * @param array<string, string> $labels   key => axis label
 * @param array<string, string> $tooltips key => tooltip
 */
function stats_column_chart(array $series, array $labels, array $tooltips, string $unit, string $aria_label) : void
{
  $n = count($series);

  if (($n === 0) || (max($series) <= 0))
  {
    echo '<p class="stats_empty">' . escape_html(get_vocab('stats_no_data')) . "</p>\n";
    return;
  }

  $width = 720;
  $height = 240;
  $margin_left = 44;
  $margin_right = 8;
  $margin_top = 12;
  $margin_bottom = 28;
  $plot_width = $width - $margin_left - $margin_right;
  $plot_height = $height - $margin_top - $margin_bottom;

  // Choose a "round" step for the y-axis, with no more than about five gridlines
  $max = max($series);
  $step = pow(10, floor(log10($max / 4)));
  foreach (array(1, 2, 2.5, 5, 10) as $factor)
  {
    if ($max / ($step * $factor) <= 5)
    {
      $step *= $factor;
      break;
    }
  }
  $top = ceil($max / $step) * $step;

  echo "<svg class=\"stats_svg\" viewBox=\"0 0 $width $height\" role=\"img\" aria-label=\"" . escape_html($aria_label) . "\">\n";

  // Gridlines and y-axis labels
  for ($v = 0; $v <= $top + 1e-9; $v += $step)
  {
    $y = round($margin_top + $plot_height - $plot_height * $v / $top, 2);
    $class = ($v == 0) ? 'stats_baseline' : 'stats_gridline';
    echo "<line class=\"$class\" x1=\"$margin_left\" x2=\"" . ($width - $margin_right) . "\" y1=\"$y\" y2=\"$y\"/>";
    echo '<text class="stats_axis" x="' . ($margin_left - 6) . '" y="' . ($y + 4) . '" text-anchor="end">' .
         escape_html(stats_number($v)) . "</text>\n";
  }

  $band = $plot_width / $n;
  $bar_width = min(28, max(2, $band - 2));
  $label_every = (int) ceil($n / 14);  // No more than about 14 labels on the x-axis
  $baseline = $margin_top + $plot_height;
  $i = 0;

  foreach ($series as $key => $value)
  {
    $x = $margin_left + $band * $i + ($band - $bar_width) / 2;
    $h = $plot_height * $value / $top;
    $y = $baseline - $h;
    $r = min(4, $bar_width / 2, $h);

    // The whole band is the hover target, which is easier to hit than the bar itself
    echo '<g class="stats_column" data-tooltip="' . escape_html($tooltips[$key]) . '">';
    echo '<rect class="stats_hit" x="' . round($margin_left + $band * $i, 2) . "\" y=\"$margin_top\" width=\"" .
         round($band, 2) . "\" height=\"$plot_height\"/>";
    if ($h > 0)
    {
      // Rounded top, square base sitting on the axis
      $x1 = round($x, 2);
      $x2 = round($x + $bar_width, 2);
      $yt = round($y, 2);
      echo "<path class=\"stats_mark\" d=\"M$x1,$baseline V" . ($yt + $r) . " Q$x1,$yt " . ($x1 + $r) . ",$yt H" .
           ($x2 - $r) . " Q$x2,$yt $x2," . ($yt + $r) . " V$baseline Z\"/>";
    }
    echo '</g>';

    if ($i % $label_every === 0)
    {
      echo '<text class="stats_axis" x="' . round($margin_left + $band * ($i + 0.5), 2) . '" y="' . ($height - 8) .
           '" text-anchor="middle">' . escape_html($labels[$key]) . '</text>';
    }
    echo "\n";
    $i++;
  }

  echo '<text class="stats_axis" x="4" y="10">' . escape_html($unit) . '</text>';
  echo "</svg>\n";
}


// ---------------------------------------------------------------------------
// Parameters
// ---------------------------------------------------------------------------

// Use 'filter_area' rather than 'area' because standard_vars.inc.php already uses $area
$filter_area = get_form_var('filter_area', 'int') ?? 0;
$filter_type = get_form_var('filter_type', 'string') ?? '';
$from = get_form_var('from', 'string') ?? '';
$to = get_form_var('to', 'string') ?? '';
$csv = get_form_var('csv', 'string') ?? '';

$tz = new DateTimeZone($timezone);
$today = new DateTimeImmutable('today', $tz);

$parse_date = function (string $s) use ($tz) : ?DateTimeImmutable
{
  $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, $tz);
  return ($d && ($d->format('Y-m-d') === $s)) ? $d : null;
};

// Default to the year to date
$d_from = $parse_date($from) ?? $today->setDate((int) $today->format('Y'), 1, 1);
$d_to = $parse_date($to) ?? $today;
if ($d_to < $d_from)
{
  [$d_from, $d_to] = [$d_to, $d_from];
}
$from = $d_from->format('Y-m-d');
$to = $d_to->format('Y-m-d');

$t_from = $d_from->getTimestamp();
$t_to = $d_to->modify('+1 day')->getTimestamp();  // Exclusive

if (!in_array($filter_type, $booking_types ?? array(), true))
{
  $filter_type = '';
}


// ---------------------------------------------------------------------------
// Areas and rooms
// ---------------------------------------------------------------------------

$areas = array();
$sql = "SELECT id, area_name, disabled, timezone, enable_periods, resolution,
               morningstarts, morningstarts_minutes, eveningends, eveningends_minutes
          FROM " . _tbl('area') . "
      ORDER BY disabled, sort_key, area_name";
$res = db()->query($sql);

while (false !== ($row = $res->next_row_keyed()))
{
  if ($row['enable_periods'] ?? $enable_periods)
  {
    continue;
  }

  $start_minutes = ($row['morningstarts'] ?? $morningstarts) * 60 + ($row['morningstarts_minutes'] ?? $morningstarts_minutes);
  // The last slot starts at eveningends and lasts for one resolution
  $end_minutes = ($row['eveningends'] ?? $eveningends) * 60 + ($row['eveningends_minutes'] ?? $eveningends_minutes) +
                 intdiv((int) ($row['resolution'] ?? $resolution), 60);

  $areas[(int) $row['id']] = array(
      'name'          => $row['area_name'],
      'disabled'      => (bool) $row['disabled'],
      'tz'            => new DateTimeZone($row['timezone'] ?: $timezone),
      'start_minutes' => $start_minutes,
      'end_minutes'   => $end_minutes,
      'open_minutes'  => max(0, $end_minutes - $start_minutes)
    );
}

if (!isset($areas[$filter_area]))
{
  $filter_area = 0;
}

// All enabled rooms, plus any disabled rooms that were booked during the period
$params = array($t_to, $t_from);
$sql = "SELECT R.id, R.room_name, R.area_id, R.capacity
          FROM " . _tbl('room') . " R
         WHERE (R.disabled = 0
                OR EXISTS (SELECT 1
                             FROM " . _tbl('entry') . " E
                            WHERE E.room_id = R.id
                              AND E.start_time < ?
                              AND E.end_time > ?))";
if ($filter_area)
{
  $sql .= " AND R.area_id = ?";
  $params[] = $filter_area;
}
$sql .= " ORDER BY R.area_id, R.sort_key, R.room_name";

$rooms = array();
$res = db()->query($sql, $params);

while (false !== ($row = $res->next_row_keyed()))
{
  $area_id = (int) $row['area_id'];
  if (!isset($areas[$area_id]))
  {
    // Periods area
    continue;
  }
  $rooms[(int) $row['id']] = array(
      'name'      => $row['room_name'],
      'area'      => $area_id,
      'capacity'  => (int) $row['capacity'],
      'hours'     => 0.0,
      'bookings'  => 0,
      'available' => 0.0,
      'occupants' => array()
    );
}

// Rooms with the same name in different areas need to be told apart when showing all areas
$name_counts = array_count_values(array_column($rooms, 'name'));
foreach ($rooms as &$room_data)
{
  $room_data['label'] = (($name_counts[$room_data['name']] > 1) && !$filter_area) ?
    get_vocab('stats_room_in_area', $room_data['name'], $areas[$room_data['area']]['name']) :
    $room_data['name'];
}
unset($room_data);


// ---------------------------------------------------------------------------
// Available hours per room (visible days x opening hours of the area)
// ---------------------------------------------------------------------------

$weekday_counts = array_fill(0, 7, 0);  // How many Sundays, Mondays, etc. there are in the period
for ($d = $d_from; $d <= $d_to; $d = $d->modify('+1 day'))
{
  $weekday_counts[(int) $d->format('w')]++;
}

$open_days = 0;
foreach ($weekday_counts as $weekday => $count)
{
  if (!in_array($weekday, $hidden_days))
  {
    $open_days += $count;
  }
}

foreach ($rooms as &$room_data)
{
  $room_data['available'] = $open_days * $areas[$room_data['area']]['open_minutes'] / 60;
}
unset($room_data);


// ---------------------------------------------------------------------------
// Bookings
// ---------------------------------------------------------------------------

$params = array($t_to, $t_from);
$sql = "SELECT E.start_time, E.end_time, E.room_id, E.name, E.create_by, E.type, E.repeat_id
          FROM " . _tbl('entry') . " E, " . _tbl('room') . " R
         WHERE E.room_id = R.id
           AND E.start_time < ?
           AND E.end_time > ?";
if ($filter_area)
{
  $sql .= " AND R.area_id = ?";
  $params[] = $filter_area;
}
if ($filter_type !== '')
{
  $sql .= " AND E.type = ?";
  $params[] = $filter_type;
}

$occupants = array();  // normalised name => data
$creators = array();
$types = array();
$months = array();
$heatmap = array();    // [weekday][hour] => minutes booked
$total_hours = 0.0;
$total_bookings = 0;
$total_repeats = 0;

// Groups variations of the same name, eg "Jane  Smith" and "jane smith"
$normalise_spaces = function (string $s) : string
{
  return trim(preg_replace('/\s+/u', ' ', $s));
};

$res = db()->query($sql, $params);

while (false !== ($row = $res->next_row_keyed()))
{
  $room_id = (int) $row['room_id'];
  if (!isset($rooms[$room_id]))
  {
    continue;
  }

  // Only count the part of the booking inside the period
  $start = max((int) $row['start_time'], $t_from);
  $end = min((int) $row['end_time'], $t_to);
  if ($end <= $start)
  {
    continue;
  }
  $hours = ($end - $start) / 3600;

  $total_hours += $hours;
  $total_bookings++;
  if (!empty($row['repeat_id']))
  {
    $total_repeats++;
  }

  $rooms[$room_id]['hours'] += $hours;
  $rooms[$room_id]['bookings']++;

  // Occupants
  $name = $normalise_spaces((string) $row['name']);
  $key = mb_strtolower($name);
  if ($key === '')
  {
    $name = get_vocab('stats_no_name');
    $key = '';
  }
  if (!isset($occupants[$key]))
  {
    $occupants[$key] = array('variants' => array(), 'hours' => 0.0, 'bookings' => 0, 'rooms' => array());
  }
  $occupant = &$occupants[$key];
  $occupant['variants'][$name] = ($occupant['variants'][$name] ?? 0) + 1;
  $occupant['hours'] += $hours;
  $occupant['bookings']++;
  $occupant['rooms'][$room_id] = ($occupant['rooms'][$room_id] ?? 0) + $hours;
  unset($occupant);

  $rooms[$room_id]['occupants'][$key] = ($rooms[$room_id]['occupants'][$key] ?? 0) + $hours;

  // Users who created the booking
  $username = (string) $row['create_by'];
  if (!isset($creators[$username]))
  {
    $creators[$username] = array('hours' => 0.0, 'bookings' => 0);
  }
  $creators[$username]['hours'] += $hours;
  $creators[$username]['bookings']++;

  // Booking types
  $type = (string) $row['type'];
  if (!isset($types[$type]))
  {
    $types[$type] = array('hours' => 0.0, 'bookings' => 0);
  }
  $types[$type]['hours'] += $hours;
  $types[$type]['bookings']++;

  // Split the booking up by month and by hour of the day, in the area's timezone
  $area_tz = $areas[$rooms[$room_id]['area']]['tz'];
  $t = $start;
  while ($t < $end)
  {
    $dt = (new DateTimeImmutable("@$t"))->setTimezone($area_tz);
    $next = min($end, $t + 3600 - ((int) $dt->format('i') * 60 + (int) $dt->format('s')));
    $weekday = (int) $dt->format('w');
    $hour = (int) $dt->format('G');
    $heatmap[$weekday][$hour] = ($heatmap[$weekday][$hour] ?? 0) + ($next - $t) / 60;
    $month_key = $dt->format('Y-m');
    $months[$month_key] = ($months[$month_key] ?? 0) + ($next - $t) / 3600;
    $t = $next;
  }
}

// Use the most common spelling of each occupant's name, and find their most used room
foreach ($occupants as &$occupant)
{
  arsort($occupant['variants']);
  $occupant['name'] = stats_first_key($occupant['variants']);
  arsort($occupant['rooms']);
  $occupant['top_room'] = stats_first_key($occupant['rooms']);
}
unset($occupant);
uasort($occupants, function ($a, $b) {
  return $b['hours'] <=> $a['hours'];
});

foreach ($rooms as &$room_data)
{
  $room_data['occupancy'] = ($room_data['available'] > 0) ? $room_data['hours'] / $room_data['available'] : 0;
  arsort($room_data['occupants']);
  $room_data['top_occupant'] = stats_first_key($room_data['occupants']);
}
unset($room_data);

$rooms_by_hours = $rooms;
uasort($rooms_by_hours, function ($a, $b) {
  return $b['hours'] <=> $a['hours'];
});

uasort($creators, function ($a, $b) {
  return $b['bookings'] <=> $a['bookings'];
});

uasort($types, function ($a, $b) {
  return $b['hours'] <=> $a['hours'];
});

// Fill in the months with no bookings
$monthly_series = array();
for ($m = $d_from->modify('first day of this month'); $m <= $d_to; $m = $m->modify('+1 month'))
{
  $monthly_series[$m->format('Y-m')] = $months[$m->format('Y-m')] ?? 0;
}

$total_available = array_sum(array_column($rooms, 'available'));
$overall_occupancy = ($total_available > 0) ? $total_hours / $total_available : 0;


// ---------------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------------

if (in_array($csv, array('rooms', 'occupants', 'creators'), true))
{
  $filename = get_vocab('stats_csv_filename', $csv, $from, $to);
  http_headers(array(
      'Content-Type: text/csv; charset=utf-8',
      'Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"'
    ));

  $out = fopen('php://output', 'w');
  if ($csv_bom)
  {
    fwrite($out, get_bom('utf-8'));
  }
  $escape = version_compare(PHP_VERSION, '7.4.0', '<') ? "\\" : '';
  $put = function (array $values) use ($out, $csv_col_sep, $escape) {
    fputcsv($out, array_map(__NAMESPACE__ . '\stats_csv_value', $values), $csv_col_sep, '"', $escape);
  };

  switch ($csv)
  {
    case 'rooms':
      $put(array(get_vocab('room'), get_vocab('area'), get_vocab('stats_bookings'), get_vocab('stats_hours'),
                 get_vocab('stats_available_hours'), get_vocab('stats_occupancy_percent'), get_vocab('stats_main_occupant')));
      foreach ($rooms_by_hours as $r)
      {
        $put(array($r['name'], $areas[$r['area']]['name'], $r['bookings'], round($r['hours'], 1),
                   round($r['available'], 1), round($r['occupancy'] * 100, 1),
                   isset($r['top_occupant']) ? $occupants[$r['top_occupant']]['name'] : ''));
      }
      break;

    case 'occupants':
      $put(array(get_vocab('stats_occupant'), get_vocab('stats_bookings'), get_vocab('stats_hours'),
                 get_vocab('stats_distinct_rooms'), get_vocab('stats_most_used_room')));
      foreach ($occupants as $o)
      {
        $put(array($o['name'], $o['bookings'], round($o['hours'], 1), count($o['rooms']),
                   $rooms[$o['top_room']]['label']));
      }
      break;

    case 'creators':
      $put(array(get_vocab('users.name'), get_vocab('users.display_name'), get_vocab('stats_bookings_created'),
                 get_vocab('stats_hours')));
      foreach ($creators as $username => $c)
      {
        $put(array($username, auth()->getDisplayName($username) ?? '', $c['bookings'], round($c['hours'], 1)));
      }
      break;
  }

  fclose($out);
  exit;
}


// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

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

$url = function (array $extra) use ($from, $to, $filter_area, $filter_type) : string
{
  $params = array_merge(
      array('from' => $from, 'to' => $to, 'filter_area' => $filter_area, 'filter_type' => $filter_type),
      $extra
    );
  return multisite(this_page() . '?' . http_build_query($params, '', '&'));
};

$this_year = (int) $today->format('Y');
$shortcuts = array(
    get_vocab('stats_this_year')       => array("$this_year-01-01", $today->format('Y-m-d')),
    get_vocab('stats_last_12_months')  => array($today->modify('-1 year +1 day')->format('Y-m-d'), $today->format('Y-m-d')),
    (string) ($this_year - 1)          => array(($this_year - 1) . '-01-01', ($this_year - 1) . '-12-31'),
    get_vocab('stats_this_month')      => array($today->format('Y-m-01'), $today->format('Y-m-d')),
    get_vocab('stats_next_30_days')    => array($today->format('Y-m-d'), $today->modify('+30 days')->format('Y-m-d'))
  );

$date_format = array('date_type' => IntlDateFormatter::MEDIUM, 'time_type' => IntlDateFormatter::NONE);
$month_format = array('skeleton' => 'MMM', 'pattern' => 'LLL');
$month_year_format = array('skeleton' => 'MMMyy', 'pattern' => 'LLL yy');

$month_label = function (string $ym, bool $with_year) use ($month_format, $month_year_format, $tz) : string
{
  $t = (new DateTimeImmutable("$ym-15", $tz))->getTimestamp();
  return datetime_format(($with_year) ? $month_year_format : $month_format, $t);
};

// Show the week starting on the configured day, leaving out hidden days
$weekdays = array();
for ($i = 0; $i < 7; $i++)
{
  $weekday = ($weekstarts + $i) % 7;
  if (!in_array($weekday, $hidden_days) && ($weekday_counts[$weekday] > 0))
  {
    $weekdays[] = $weekday;
  }
}

echo "<div class=\"stats\">\n";
echo '<h2>' . escape_html(get_vocab('stats_title')) . "</h2>\n";

$subtitle = array(
    datetime_format($date_format, $t_from) . ' – ' . datetime_format($date_format, $d_to->getTimestamp()),
    ($filter_area) ? $areas[$filter_area]['name'] : get_vocab('stats_all_areas')
  );
if ($filter_type !== '')
{
  $subtitle[] = stats_type_name($filter_type);
}
echo '<p class="stats_subtitle">' . escape_html(implode(' · ', $subtitle)) . "</p>\n";

// Filters
echo '<form class="stats_filters" method="get" action="' . escape_html(multisite(this_page())) . "\">\n";
echo '<label>' . escape_html(get_vocab('stats_from')) . ' <input type="date" name="from" value="' . escape_html($from) . "\" required></label>\n";
echo '<label>' . escape_html(get_vocab('stats_to')) . ' <input type="date" name="to" value="' . escape_html($to) . "\" required></label>\n";
echo '<label>' . escape_html(get_vocab('area')) . "\n<select name=\"filter_area\">\n";
echo '<option value="0">' . escape_html(get_vocab('stats_all')) . "</option>\n";
foreach ($areas as $id => $a)
{
  $text = ($a['disabled']) ? get_vocab('stats_disabled_item', $a['name']) : $a['name'];
  echo "<option value=\"$id\"" . (($id === $filter_area) ? ' selected' : '') . '>' . escape_html($text) . "</option>\n";
}
echo "</select></label>\n";
echo '<label>' . escape_html(get_vocab('type')) . "\n<select name=\"filter_type\">\n";
echo '<option value="">' . escape_html(get_vocab('stats_all')) . "</option>\n";
foreach ($booking_types as $type)
{
  echo '<option value="' . escape_html($type) . '"' . (($type === $filter_type) ? ' selected' : '') . '>' .
       escape_html(stats_type_name($type)) . "</option>\n";
}
echo "</select></label>\n";
echo '<button type="submit">' . escape_html(get_vocab('stats_show')) . "</button>\n";
echo "</form>\n";

echo "<div class=\"stats_shortcuts\">\n";
foreach ($shortcuts as $text => [$shortcut_from, $shortcut_to])
{
  echo '<a href="' . escape_html($url(array('from' => $shortcut_from, 'to' => $shortcut_to))) . '">' . escape_html($text) . "</a>\n";
}
echo "</div>\n";

// Key figures
$top_room = stats_first_key($rooms_by_hours);
$top_occupant = stats_first_key($occupants);

$peak = null;
foreach ($heatmap as $weekday => $hours_of_day)
{
  foreach ($hours_of_day as $hour => $minutes)
  {
    if (!isset($peak) || ($minutes > $peak[2]))
    {
      $peak = array($weekday, $hour, $minutes);
    }
  }
}

$figures = array(
    array(stats_number($total_bookings), get_vocab('stats_bookings')),
    array(stats_number($total_hours), get_vocab('stats_hours_booked')),
    array(stats_percent($overall_occupancy), get_vocab('stats_average_occupancy')),
    array(stats_number(count($occupants)), get_vocab('stats_distinct_occupants')),
    array(($total_bookings) ? get_vocab('stats_minutes_short', stats_number($total_hours / $total_bookings * 60)) : '–',
          get_vocab('stats_average_duration')),
    array(($total_bookings) ? stats_percent($total_repeats / $total_bookings) : '–', get_vocab('stats_repeat_share'))
  );

$small_figures = array();
if (isset($top_room) && ($rooms_by_hours[$top_room]['hours'] > 0))
{
  $small_figures[] = array($rooms_by_hours[$top_room]['label'], get_vocab('stats_busiest_room'));
}
if (isset($top_occupant))
{
  $small_figures[] = array($occupants[$top_occupant]['name'], get_vocab('stats_top_occupant'));
}
if (isset($peak))
{
  $small_figures[] = array(get_vocab('stats_time_slot', day_name($peak[0]), $peak[1], $peak[1] + 1),
                           get_vocab('stats_peak_time_slot'));
}

echo "<div class=\"stats_figures\">\n";
foreach ($figures as [$value, $label])
{
  echo '<div class="stats_figure"><div class="stats_figure_value">' . escape_html($value) . '</div>' .
       '<div class="stats_figure_label">' . escape_html($label) . "</div></div>\n";
}
foreach ($small_figures as [$value, $label])
{
  echo '<div class="stats_figure"><div class="stats_figure_value small">' . escape_html($value) . '</div>' .
       '<div class="stats_figure_label">' . escape_html($label) . "</div></div>\n";
}
echo "</div>\n";

echo "<div class=\"stats_grid\">\n";

// Busiest rooms
echo "<section class=\"stats_card\">\n";
echo '<h3>' . escape_html(get_vocab('stats_rooms_title')) . "</h3>\n";
echo '<p class="stats_note">' . escape_html(get_vocab('stats_rooms_note')) . "</p>\n";
$rows = array();
foreach ($rooms_by_hours as $r)
{
  $tooltip = array(
      $r['label'],
      get_vocab('stats_hours_in_bookings', stats_number($r['hours'], 1), stats_number($r['bookings'])),
      get_vocab('stats_occupancy_of_available', stats_percent($r['occupancy']), stats_number($r['available']))
    );
  if (isset($r['top_occupant']))
  {
    $tooltip[] = get_vocab('stats_main_occupant_is', $occupants[$r['top_occupant']]['name']);
  }
  $rows[] = array(
      $r['label'],
      $r['hours'],
      stats_hours($r['hours']) . ' · ' . stats_percent($r['occupancy']),
      implode("\n", $tooltip)
    );
}
stats_bar_chart($rows, get_vocab('stats_rooms_title'));
echo '<div class="stats_actions"><a href="' . escape_html($url(array('csv' => 'rooms'))) . '">' .
     escape_html(get_vocab('stats_download_csv')) . "</a></div>\n";
echo "</section>\n";

// Occupants
$top_n = 15;
$table_n = 100;
echo "<section class=\"stats_card\">\n";
echo '<h3>' . escape_html(get_vocab('stats_occupants_title')) . "</h3>\n";
echo '<p class="stats_note">' . escape_html(get_vocab('stats_occupants_note', $top_n)) . "</p>\n";
$rows = array();
foreach (array_slice($occupants, 0, $top_n, true) as $o)
{
  $rows[] = array(
      $o['name'],
      $o['hours'],
      stats_hours($o['hours']),
      implode("\n", array(
          $o['name'],
          get_vocab('stats_hours_in_bookings', stats_number($o['hours'], 1), stats_number($o['bookings'])),
          get_vocab('stats_rooms_used', stats_number(count($o['rooms'])), $rooms[$o['top_room']]['label'])
        ))
    );
}
stats_bar_chart($rows, get_vocab('stats_occupants_title'));
if (count($occupants) > $top_n)
{
  echo '<details><summary>' . escape_html(get_vocab('stats_show_top_n', min($table_n, count($occupants)))) . "</summary>\n";
  echo '<div class="stats_scroll"><table class="stats_table"><thead><tr>';
  echo '<th class="numeric">#</th>';
  echo '<th>' . escape_html(get_vocab('stats_occupant')) . '</th>';
  echo '<th class="numeric">' . escape_html(get_vocab('stats_bookings')) . '</th>';
  echo '<th class="numeric">' . escape_html(get_vocab('stats_hours')) . '</th>';
  echo '<th>' . escape_html(get_vocab('stats_most_used_room')) . '</th>';
  echo "</tr></thead><tbody>\n";
  $i = 0;
  foreach (array_slice($occupants, 0, $table_n, true) as $o)
  {
    $i++;
    echo "<tr><td class=\"numeric\">$i</td>";
    echo '<td>' . escape_html($o['name']) . '</td>';
    echo '<td class="numeric">' . escape_html(stats_number($o['bookings'])) . '</td>';
    echo '<td class="numeric">' . escape_html(stats_number($o['hours'], 1)) . '</td>';
    echo '<td>' . escape_html($rooms[$o['top_room']]['label']) . "</td></tr>\n";
  }
  echo "</tbody></table></div></details>\n";
}
echo '<div class="stats_actions"><a href="' . escape_html($url(array('csv' => 'occupants'))) . '">' .
     escape_html(get_vocab('stats_download_csv')) . "</a></div>\n";
echo "</section>\n";

// Hours per month
echo "<section class=\"stats_card wide\">\n";
echo '<h3>' . escape_html(get_vocab('stats_monthly_title')) . "</h3>\n";
$several_years = ($d_from->format('Y') !== $d_to->format('Y'));
$labels = array();
$tooltips = array();
foreach ($monthly_series as $ym => $value)
{
  $labels[$ym] = $month_label($ym, $several_years);
  $tooltips[$ym] = $month_label($ym, true) . "\n" . get_vocab('stats_hours_long', stats_number($value, 1));
}
stats_column_chart($monthly_series, $labels, $tooltips, get_vocab('stats_hours_axis'), get_vocab('stats_monthly_title'));
echo "</section>\n";

// Heatmap
echo "<section class=\"stats_card wide\">\n";
echo '<h3>' . escape_html(get_vocab('stats_heatmap_title')) . "</h3>\n";
echo '<p class="stats_note">' . escape_html(get_vocab('stats_heatmap_note')) . "</p>\n";

$first_hour = 24;
$last_hour = 0;
foreach ($areas as $id => $a)
{
  if ($filter_area && ($id !== $filter_area))
  {
    continue;
  }
  $first_hour = min($first_hour, intdiv($a['start_minutes'], 60));
  $last_hour = max($last_hour, (int) ceil($a['end_minutes'] / 60));
}
$last_hour = min(24, $last_hour);

$n_rooms = count($rooms);
$n_steps = 7;  // Number of colours in the scale (see .stats_heat_N in mrbs.css.php)

if ($n_rooms && $total_bookings && ($first_hour < $last_hour))
{
  echo '<div class="stats_scroll"><table class="stats_heatmap"><thead><tr><th></th>';
  for ($h = $first_hour; $h < $last_hour; $h++)
  {
    echo "<th>$h</th>";
  }
  echo '<th class="numeric">' . escape_html(get_vocab('stats_average_short')) . "</th></tr></thead><tbody>\n";

  foreach ($weekdays as $weekday)
  {
    $possible = $n_rooms * $weekday_counts[$weekday] * 60;  // Room-minutes available in each hour slot
    echo '<tr><th class="weekday">' . escape_html(day_name($weekday)) . '</th>';
    $sum = 0;
    for ($h = $first_hour; $h < $last_hour; $h++)
    {
      $p = ($heatmap[$weekday][$h] ?? 0) / $possible;
      $sum += $p;
      $class = ($p > 0) ? 'stats_heat_' . min($n_steps - 1, (int) floor($p * $n_steps)) : 'stats_heat_none';
      $tooltip = get_vocab('stats_time_slot', day_name($weekday), $h, $h + 1) . "\n" .
                 get_vocab('stats_rooms_booked', stats_percent($p));
      echo "<td class=\"cell $class\" data-tooltip=\"" . escape_html($tooltip) . '">' .
           (($p > 0) ? escape_html(stats_number($p * 100)) : '') . '</td>';
    }
    echo '<td class="average">' . escape_html(stats_percent($sum / ($last_hour - $first_hour))) . "</td></tr>\n";
  }
  echo "</tbody></table></div>\n";

  echo '<div class="stats_legend"><span class="text">' . escape_html(stats_percent(0)) . '</span>';
  for ($i = 0; $i < $n_steps; $i++)
  {
    echo "<span class=\"swatch stats_heat_$i\"></span>";
  }
  echo '<span class="text">' . escape_html(stats_percent(1)) . "</span></div>\n";
}
else
{
  echo '<p class="stats_empty">' . escape_html(get_vocab('stats_no_data')) . "</p>\n";
}
echo "</section>\n";

// Booking types
echo "<section class=\"stats_card\">\n";
echo '<h3>' . escape_html(get_vocab('stats_types_title')) . "</h3>\n";
$rows = array();
foreach ($types as $type => $t)
{
  $type_name = stats_type_name((string) $type);
  $rows[] = array(
      $type_name,
      $t['hours'],
      stats_hours($t['hours']),
      implode("\n", array(
          $type_name,
          get_vocab('stats_hours_in_bookings', stats_number($t['hours'], 1), stats_number($t['bookings'])),
          get_vocab('stats_share_of_total', stats_percent(($total_hours) ? $t['hours'] / $total_hours : 0))
        ))
    );
}
stats_bar_chart($rows, get_vocab('stats_types_title'));
echo "</section>\n";

// Users who create the bookings
$top_n = 10;
echo "<section class=\"stats_card\">\n";
echo '<h3>' . escape_html(get_vocab('stats_creators_title')) . "</h3>\n";
echo '<p class="stats_note">' . escape_html(get_vocab('stats_creators_note', $top_n)) . "</p>\n";
$rows = array();
foreach (array_slice($creators, 0, $top_n, true) as $username => $c)
{
  $username = (string) $username;
  $display_name = auth()->getDisplayName($username) ?? $username;
  $rows[] = array(
      $display_name,
      $c['bookings'],
      stats_number($c['bookings']),
      implode("\n", array(
          ($display_name !== $username) ? get_vocab('compound_name', $username, $display_name) : $username,
          get_vocab('stats_bookings_and_hours', stats_number($c['bookings']), stats_number($c['hours'], 1))
        ))
    );
}
stats_bar_chart($rows, get_vocab('stats_creators_title'));
echo '<div class="stats_actions"><a href="' . escape_html($url(array('csv' => 'creators'))) . '">' .
     escape_html(get_vocab('stats_download_csv')) . "</a></div>\n";
echo "</section>\n";

// Per-room table
echo "<section class=\"stats_card wide\">\n";
echo '<h3>' . escape_html(get_vocab('stats_room_table_title')) . "</h3>\n";
echo '<div class="stats_scroll"><table class="stats_table"><thead><tr>';
echo '<th>' . escape_html(get_vocab('room')) . '</th>';
echo '<th>' . escape_html(get_vocab('area')) . '</th>';
echo '<th class="numeric">' . escape_html(get_vocab('capacity')) . '</th>';
echo '<th class="numeric">' . escape_html(get_vocab('stats_bookings')) . '</th>';
echo '<th class="numeric">' . escape_html(get_vocab('stats_hours')) . '</th>';
echo '<th>' . escape_html(get_vocab('stats_occupancy')) . '</th>';
echo '<th class="numeric">' . escape_html(get_vocab('stats_occupants')) . '</th>';
echo '<th>' . escape_html(get_vocab('stats_main_occupant')) . '</th>';
echo "</tr></thead><tbody>\n";
foreach ($rooms_by_hours as $r)
{
  echo '<tr><td>' . escape_html($r['name']) . '</td>';
  echo '<td>' . escape_html($areas[$r['area']]['name']) . '</td>';
  echo '<td class="numeric">' . (($r['capacity']) ? escape_html(stats_number($r['capacity'])) : '–') . '</td>';
  echo '<td class="numeric">' . escape_html(stats_number($r['bookings'])) . '</td>';
  echo '<td class="numeric">' . escape_html(stats_number($r['hours'], 1)) . '</td>';
  echo '<td><span class="stats_meter"><span style="width:' . round(min(1, $r['occupancy']) * 100, 1) . '%"></span></span>' .
       escape_html(stats_percent($r['occupancy'])) . '</td>';
  echo '<td class="numeric">' . escape_html(stats_number(count($r['occupants']))) . '</td>';
  echo '<td>';
  if (isset($r['top_occupant']))
  {
    echo escape_html($occupants[$r['top_occupant']]['name']) . ' <span class="stats_muted">(' .
         escape_html(stats_hours($r['occupants'][$r['top_occupant']])) . ')</span>';
  }
  else
  {
    echo '–';
  }
  echo "</td></tr>\n";
}
echo "</tbody></table></div>\n";
echo "</section>\n";

echo "</div>\n";  // .stats_grid

// Explain how occupancy is calculated
$opening_hours = array();
foreach ($areas as $id => $a)
{
  if (($filter_area && ($id !== $filter_area)) || $a['disabled'])
  {
    continue;
  }
  $opening_hours[] = get_vocab('stats_opening_hours', $a['name'],
                               sprintf('%d:%02d', intdiv($a['start_minutes'], 60), $a['start_minutes'] % 60),
                               sprintf('%d:%02d', intdiv($a['end_minutes'], 60), $a['end_minutes'] % 60));
}
echo '<p class="stats_footnote">' . escape_html(get_vocab('stats_footnote', implode('; ', $opening_hours))) . "</p>\n";

echo "</div>\n";  // .stats

echo "<div id=\"stats_tooltip\" role=\"tooltip\"></div>\n";

print_footer();
