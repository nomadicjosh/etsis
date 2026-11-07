/*!
* Start Bootstrap - Modern Business v5.0.7 (https://startbootstrap.com/template-overviews/modern-business)
* Copyright 2013-2023 Start Bootstrap
* Licensed under MIT (https://github.com/StartBootstrap/startbootstrap-modern-business/blob/master/LICENSE)
*/
// This file is intentionally blank
// Use this file to add JavaScript to your project

$(document).ready(function () {
    var url = window.location.pathname;
    // Select all nav links and filter for the one matching the current URL
    $('ul.navbar-nav a[href="' + url + '"]').each(function () {
        // Add 'active' class to the matching link or its parent 'li'
        if ($(this).hasClass('nav-link')) {
            $(this).addClass('active');
        } else {
            $(this).parent().addClass('active');
        }
    });
});
