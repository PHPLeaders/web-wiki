<?php
/*
 * Dokuwiki's Main Configuration File - Local Settings
 * Auto-generated by config plugin
 * Run for user: lsmith2
 * Date: Thu, 06 Mar 2008 20:30:50 +0000
 */


$conf['title'] = 'PHP Wiki';
$conf['template'] = 'phpwiki';
$conf['useacl'] = 1;
$conf['authtype'] = 'phpcvs';
$conf['superuser'] = '@admin';
$conf['manager'] = '@phpcvs';
$conf['registernotify'] = 'webmaster@php.net';
$conf['updatecheck'] = 0;
$conf['userewrite'] = '1';
$conf['useslash'] = 1;
$conf['mailfrom'] = 'php-webmaster@lists.php.net';
$conf['send404'] = 1;
$conf['rss_content'] = 'htmldiff';
$conf['plugin']['hcalendar']['locale'] = 'en_US';
$conf['spellchecker'] = '1';

@include(DOKU_CONF.'local.protected.php');

// end auto-generated content
