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
  // Show the text in the data-tooltip attribute of chart elements in a tooltip
  // that follows the mouse, keeping it inside the window.
  ?>
  const tooltip = $('#stats_tooltip');

  $('.stats [data-tooltip]')
    .on('mousemove', function(e) {
      tooltip.text($(this).attr('data-tooltip')).show();
      const width = tooltip.outerWidth();
      const height = tooltip.outerHeight();
      let x = e.clientX + 14;
      let y = e.clientY + 14;
      if (x + width > window.innerWidth - 8)
      {
        x = e.clientX - width - 14;
      }
      if (y + height > window.innerHeight - 8)
      {
        y = e.clientY - height - 14;
      }
      tooltip.css({left: x + 'px', top: y + 'px'});
    })
    .on('mouseleave', function() {
      tooltip.hide();
    });

});
