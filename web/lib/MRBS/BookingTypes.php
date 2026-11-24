<?php
declare(strict_types=1);
namespace MRBS;

/**
 * Booking types that admins can manage on the Booking Types page (edit_types.php).
 *
 * The feature is enabled by setting $booking_types_file to the path of a JSON file that
 * the web server can write to.  The file is used rather than the database so that the
 * settings are also available to the CSS files, which don't connect to the database.
 * Until the file has been created, the settings in the config file apply.
 *
 * File format:
 *   {"types": [{"code": "E", "name": "External", "color": "#ffcc99", "active": true}, ...]}
 *
 * An inactive type can't be chosen for new bookings, but keeps its name and colour so
 * that existing bookings are still displayed correctly.  The default type for new bookings
 * is set for each area on the Edit Area page.
 */
class BookingTypes
{
  public const MAX_NAME_LENGTH = 40;

  /** @var array<string, string> */
  private static $names = [];


  /**
   * Whether the feature has been enabled in the config file.
   */
  public static function isEnabled() : bool
  {
    return (self::getFile() !== null);
  }


  public static function getFile() : ?string
  {
    global $booking_types_file;

    return (isset($booking_types_file) && ($booking_types_file !== '')) ? $booking_types_file : null;
  }


  /**
   * Booking type codes are single letters, so that they fit in the type column of the
   * entry and repeat tables and can be used as CSS class names.  New types are given
   * upper case letters, but lower case ones may already have been set in the config file.
   */
  public static function isValidCode($code) : bool
  {
    return is_string($code) && (preg_match('/^[A-Za-z]$/', $code) === 1);
  }


  public static function isValidColor($color) : bool
  {
    return is_string($color) && (preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1);
  }


  /**
   * Checks a booking type name.  Names are used unescaped in the same way as vocab strings,
   * so characters that are special in HTML are not allowed.
   *
   * @return string|null The vocab tag for the error, or null if the name is valid
   */
  public static function checkName($name) : ?string
  {
    if (!is_string($name) || (trim($name) === ''))
    {
      return 'booking_type_name_empty';
    }
    if (mb_strlen(trim($name)) > self::MAX_NAME_LENGTH)
    {
      return 'booking_type_name_too_long';
    }
    if (preg_match('/[<>&"\']/', $name))
    {
      return 'booking_type_name_invalid_chars';
    }

    return null;
  }


  /**
   * Reads the booking types file.
   *
   * @return array{types: array<array{code: string, name: string, color: string, active: bool}>}|null
   *   The data, or null if the feature isn't enabled or the file doesn't exist or isn't valid
   */
  public static function load() : ?array
  {
    $file = self::getFile();

    if (!isset($file) || !is_readable($file))
    {
      return null;
    }

    $data = json_decode((string) file_get_contents($file), true);

    // Don't trigger an error here: this is called before any output, while the theme is
    // being loaded, and the Booking Types page reports invalid files.
    if (!is_array($data) || !isset($data['types']) || !is_array($data['types']))
    {
      return null;
    }

    // Skip any entries that aren't valid
    $types = [];
    foreach ($data['types'] as $type)
    {
      if (is_array($type) &&
          self::isValidCode($type['code'] ?? null) &&
          !isset($types[$type['code']]))
      {
        $name = $type['name'] ?? null;
        $types[$type['code']] = [
            'code'   => $type['code'],
            'name'   => (self::checkName($name) === null) ? trim($name) : $type['code'],
            'color'  => self::isValidColor($type['color'] ?? null) ? strtolower($type['color']) : '#ffffff',
            'active' => !empty($type['active'])
          ];
      }
    }

    return ['types' => array_values($types)];
  }


  /**
   * Gets the booking types to show on the Booking Types page: the ones in the file, or if
   * the file hasn't been created yet, the ones in the config file.
   *
   * @return array{types: array<array{code: string, name: string, color: string, active: bool}>}
   */
  public static function getData() : array
  {
    global $booking_types, $color_types;

    $data = self::load();

    if (isset($data))
    {
      return $data;
    }

    $types = [];
    foreach ($booking_types ?? [] as $code)
    {
      $code = (string) $code;
      if (!self::isValidCode($code) || isset($types[$code]))
      {
        continue;
      }
      // Remove any characters that aren't allowed in names
      $name = trim(str_replace(['<', '>', '&', '"', "'"], '', get_type_vocab($code)));
      $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);
      $types[$code] = [
          'code'   => $code,
          'name'   => ($name === '') ? $code : $name,
          'color'  => (self::isValidColor($color_types[$code] ?? null)) ? strtolower($color_types[$code]) : '#ffffff',
          'active' => true
        ];
    }

    return ['types' => array_values($types)];
  }


  /**
   * Saves the booking types file, replacing it atomically.
   *
   * @return bool Whether the file was saved
   */
  public static function save(array $data) : bool
  {
    $file = self::getFile();

    if (!isset($file))
    {
      return false;
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false)
    {
      return false;
    }

    $tmp = $file . '.tmp' . getmypid();

    if (false === file_put_contents($tmp, $json . "\n", LOCK_EX))
    {
      return false;
    }

    if (!rename($tmp, $file))
    {
      unlink($tmp);
      return false;
    }

    return true;
  }


  /**
   * Gets a number that changes whenever the booking types change, so that it can be
   * used to make browsers fetch the CSS again.
   */
  public static function getVersion() : int
  {
    $file = self::getFile();

    if (!isset($file))
    {
      return 0;
    }

    clearstatcache(true, $file);
    return (is_readable($file)) ? (int) filemtime($file) : 0;
  }


  /**
   * Gets the name given to a booking type on the Booking Types page.
   *
   * @return string|null The name, or null if the type isn't managed on that page
   */
  public static function getName(string $code) : ?string
  {
    return self::$names[$code] ?? null;
  }


  /**
   * Applies the booking types in the file, if there is one, to the MRBS settings,
   * overriding $booking_types and $color_types.  This must be called
   * after the theme's styling has been loaded, so that the colours aren't overwritten.
   */
  public static function apply() : void
  {
    global $booking_types, $color_types;

    $data = self::load();

    if (!isset($data))
    {
      return;
    }

    $active = [];
    self::$names = [];

    foreach ($data['types'] as $type)
    {
      self::$names[$type['code']] = $type['name'];
      $color_types[$type['code']] = $type['color'];
      if ($type['active'])
      {
        $active[] = $type['code'];
      }
    }

    if (!empty($active))
    {
      $booking_types = $active;
    }
  }

}
