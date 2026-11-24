<?php
declare(strict_types=1);
namespace MRBS;

require "../defaultincludes.inc";

http_headers(array("Content-type: application/x-javascript"),
             60*30);  // 30-minute expiry
?>

'use strict';

$(document).on('page_ready', function() {

  <?php
  // Ask for confirmation before submitting forms that have a data-confirm attribute
  // (eg deleting a booking type)
  ?>
  $('#booking_types form[data-confirm]').on('submit', function() {
    return window.confirm($(this).attr('data-confirm'));
  });

});
