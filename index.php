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

/**
 * Get details of a commit: tree, parent, author/committer (name, mail, date), message
 */
function git_get_commit_info($project, $hash)
{
	$info = array();
	$info['h'] = $hash;
	$info['message_full'] = '';

	$output = run_git($project, "git rev-list --header --max-count=1 $hash");
	// tree <h>
	// parent <h>
	// author <name> "<"<mail>">" <stamp> <timezone>
	// committer
	// <empty>
	//     <message>
	$pattern = '/^(author|committer) ([^<]+) <([^>]*)> ([0-9]+) (.*)$/';
	foreach ($output as $line) {
		if (substr($line, 0, 4) === 'tree') {
			$info['tree'] = substr($line, 5);
		}
		elseif (substr($line, 0, 6) === 'parent') {
			$info['parent']  = substr($line, 7);
		}
		elseif (preg_match($pattern, $line, $matches) > 0) {
			$info[$matches[1] .'_name'] = $matches[2];
			$info[$matches[1] .'_mail'] = $matches[3];
			$info[$matches[1] .'_stamp'] = $matches[4];
			$info[$matches[1] .'_timezone'] = $matches[5];
			$info[$matches[1] .'_utcstamp'] = $matches[4] - ((intval($matches[5]) / 100.0) * 3600);
		}
		elseif (substr($line, 0, 4) === '    ') {
			$info['message_full'] .= substr($line, 4) ."\n";
			if (!isset($info['message'])) {
				$info['message'] = substr($line, 4, 40);
			}
		}
	}

	return $info;
}

function git_get_heads($project)
{
	$heads = array();

	$output = run_git($project, 'git show-ref --heads');
	foreach ($output as $line) {
		$fullname = substr($line, 41);
		$name = array_pop(explode('/', $fullname));
		$heads[] = array('h' => substr($line, 0, 40), 'fullname' => "$fullname", 'name' => "$name");
	}

	return $heads;
}

function git_get_rev_list($project, $max_count = null)
{
	$cmd = 'git rev-list HEAD';
	if (!is_null($max_count)) {
		$cmd = "git rev-list --max-count=$max_count HEAD";
	}

	return run_git($project, $cmd);
}

function git_ls_tree($project, $tree)
{
	$entries = array();
	$output = run_git($project, "git ls-tree $tree");
	// 100644 blob 493b7fc4296d64af45dac64bceac2d9a96c958c1    .gitignore
	// 040000 tree 715c78b1011dc58106da2a1af2fe0aa4c829542f    doc
	foreach ($output as $line) {
		$parts = preg_split('/\s+/', $line, 4);
		$entries[] = array('name' => $parts[3], 'mode' => $parts[0], 'type' => $parts[1], 'hash' => $parts[2]);
	}

	return $entries;
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

/**
 * Makes sure the given project is valid. If it's not, this function will
 * die().
 * @return the project
 */
function validate_project($project)
{
	global $conf;

	if (!in_array($project, array_keys($conf['projects']))) {
		die('Invalid project');
	}
	return $project;
}

/**
 * Makes sure the given hash is valid. If it's not, this function will die().
 * @return the hash
 */
function validate_hash($hash)
{
	if (strlen($hash) != 40 || !preg_match('/^[0-9a-z]*$/', $hash)) {
		die('Invalid hash');

	}
	return $hash;
}

$action = 'index';
$template = '';
$page['title'] = 'ViewGit';

if (isset($_REQUEST['a'])) {
	$action = strtolower($_REQUEST['a']);
}
$page['action'] = $action;

if ($action === 'index') {
	$template = 'index';
	$page['title'] = 'List of projects - ViewGit';

	foreach (array_keys($conf['projects']) as $p) {
		$page['projects'][] = get_project_info($p);
	}
}
elseif ($action === 'archive') {
	$project = validate_project($_REQUEST['p']);
	$tree = validate_hash($_REQUEST['h']);
	$type = $_REQUEST['t'];

	// TODO check that the data passed is really valid
	if ($type === 'targz') {
		header("Content-Type: application/x-tar-gz");
		header("Content-Transfer-Encoding: binary");
		header("Content-Disposition: attachment; filename=\"$project-tree-$tree.tar.gz\";");
		$data = join("\n", run_git($project, "git archive --format=tar $tree |gzip"));
		header("Content-Length: ". strlen($data));
		echo $data;
	}
	elseif ($type === 'zip') {
		header("Content-Type: application/x-zip");
		header("Content-Transfer-Encoding: binary");
		header("Content-Disposition: attachment; filename=\"$project-tree-$tree.zip\";");
		$data = join("\n", run_git($project, "git archive --format=zip $tree"));
		header("Content-Length: ". strlen($data));
		echo $data;
	}
	else {
		die('Invalid archive type requested');
	}

	die();
}
elseif ($action === 'blob') {
	$page['project'] = validate_project($_REQUEST['p']);
	$page['hash'] = validate_hash($_REQUEST['h']);

	$lines = run_git($page['project'], "git cat-file blob $page[hash]");

	header('Content-type: text/plain');
	print(join("\n", $lines));
	die();
}
elseif ($action === 'commit') {
	$template = 'commit';
	$page['project'] = validate_project($_REQUEST['p']);
	$page['commit_id'] = validate_hash($_REQUEST['h']);

	$info = git_get_commit_info($page['project'], $page['commit_id']);

	$page['author_name'] = $info['author_name'];
	$page['author_mail'] = $info['author_mail'];
	$page['author_datetime'] = strftime($conf['datetime'], $info['author_utcstamp']);
	$page['committer_name'] = $info['committer_name'];
	$page['committer_mail'] = $info['committer_mail'];
	$page['committer_datetime'] = strftime($conf['datetime'], $info['committer_utcstamp']);
	$page['tree'] = $info['tree'];
	$page['parent'] = $info['parent'];
	$page['message'] = $info['message'];
	$page['message_full'] = $info['message_full'];

}
elseif ($action === 'summary') {
	$template = 'summary';
	$page['project'] = validate_project($_REQUEST['p']);
	$page['title'] = "$page[project] - Summary - ViewGit";
	
	$revs = git_get_rev_list($page['project'], $conf['summary_shortlog']);
	foreach ($revs as $rev) {
		$info = git_get_commit_info($page['project'], $rev);
		$page['shortlog'][] = array(
			'author' => $info['author_name'],
			'date' => strftime($conf['datetime'], $info['author_utcstamp']),
			'message' => $info['message'],
			'commit_id' => $rev,
			'tree' => $info['tree'],
		);
	}

	$heads = git_get_heads($page['project']);
	$page['heads'] = array();
	foreach ($heads as $h) {
		$info = git_get_commit_info($page['project'], $h['h']);
		$page['heads'][] = array(
			'date' => strftime($conf['datetime'], $info['author_utcstamp']),
			'h' => $h['h'],
			'fullname' => $h['fullname'],
			'name' => $h['name'],
		);
	}
}
elseif ($action === 'tree') {
	$template = 'tree';
	$page['project'] = validate_project($_REQUEST['p']);
	$page['tree'] = validate_hash($_REQUEST['h']);
	$page['title'] = "$page[project] - Tree - ViewGit";

	$page['entries'] = git_ls_tree($page['project'], $page['tree']);
}
else {
	die('Invalid action');
}

require 'templates/header.php';
require "templates/$template.php";
require 'templates/footer.php';
