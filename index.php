<?php
error_reporting(E_ALL);

require_once('inc/config.php');

function get_project_info($name)
{
	global $conf;

	$info = $conf['projects'][$name];
	$info['name'] = $name;
	$info['description'] = file_get_contents($info['repo'] .'/description');

	return $info;
}

function git_get_heads($project)
{
	$heads = array();

	$output = run_git($project, 'git-show-ref --heads');
	foreach ($output as $line) {
		$fullname = substr($line, 41);
		$name = array_pop(explode('/', $fullname));
		$heads[] = array('h' => substr($line, 0, 40), 'fullname' => "$fullname", 'name' => "$name");
	}

	return $heads;
}

function makelink($dict)
{
	$params = array();
	foreach ($dict as $k => $v) {
		$params[] = rawurlencode($k) .'='. str_replace('%2F', '/', rawurlencode($v));
	}
	if (count($params) > 0) {
		return '?'. htmlentities(join('&', $params));
	}
	return '';
}

/**
 * Executes a git command in the project repo.
 * @return array of output lines
 */
function run_git($project, $command)
{
	global $conf;

	$output = array();
	$cmd = "GIT_DIR=". $conf['projects'][$project]['repo'] ." $command";
	exec($cmd, &$output);
	return $output;
}

$action = 'index';
$template = '';
$page['title'] = 'ViewGit';

if (isset($_REQUEST['a'])) {
	$action = strtolower($_REQUEST['a']);
}

if ($action === 'index') {
	$template = 'index';

	foreach (array_keys($conf['projects']) as $p) {
		$page['projects'][] = get_project_info($p);
	}

	/*
	$page['projects'] = array(
		array('name' => 'projecta', 'description' => 'project a description'),
		array('name' => 'projectb', 'description' => 'project b description'),
	);
	*/
}
elseif ($action === 'summary') {
	$template = 'summary';
	$page['project'] = strtolower($_REQUEST['p']);
	// TODO: validate project

	$page['shortlog'] = array(
		array('author' => 'Author Name', 'date' => '2008-05-03 10:06:22', 'message' => 'Insightful commentary', 'commit_id' => '57c8cae91dd942a2e1d72cc995468abef2c2beeb'),
	);

	$heads = git_get_heads($page['project']);
	$page['heads'] = array();
	foreach ($heads as $h) {
		$page['heads'][] = array(
			'date' => '2008-05-03 10:11:23',
			'h' => $h['h'],
			'fullname' => $h['fullname'],
			'name' => $h['name'],
		);
	}
}
else {
	die('Invalid action');
}

require 'templates/header.php';
require "templates/$template.php";
require 'templates/footer.php';
