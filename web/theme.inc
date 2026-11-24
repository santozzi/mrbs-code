<?php
declare(strict_types=1);
namespace MRBS;

// Gets the styling appropriate to the theme.     If there isn't a styling file
// present for the theme then the default styling is used.

require_once "systemdefaults.inc.php";
require_once "config.inc.php";
require_once "Themes/default/styling.inc";
// Overwrite the default with the specified styling if it exists
$theme_file =  __DIR__ . "/Themes/$theme/styling.inc";
if (is_file($theme_file) && is_readable($theme_file))
{
  require $theme_file;
}

// Apply any booking types set on the Booking Types page.  This has to be done after
// the styling has been loaded so that the colours set on that page take precedence.
BookingTypes::apply();
