<?php
// +-----------------------------------------------------------------------+
// | Piwigo - a PHP based photo gallery                                    |
// +-----------------------------------------------------------------------+
// | Copyright(C) 2008-2016 Piwigo Team                  http://piwigo.org |
// | Copyright(C) 2003-2008 PhpWebGallery Team    http://phpwebgallery.net |
// | Copyright(C) 2002-2003 Pierrick LE GALL   http://le-gall.net/pierrick |
// +-----------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or modify  |
// | it under the terms of the GNU General Public License as published by  |
// | the Free Software Foundation                                          |
// |                                                                       |
// | This program is distributed in the hope that it will be useful, but   |
// | WITHOUT ANY WARRANTY; without even the implied warranty of            |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU      |
// | General Public License for more details.                              |
// |                                                                       |
// | You should have received a copy of the GNU General Public License     |
// | along with this program; if not, write to the Free Software           |
// | Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307, |
// | USA.                                                                  |
// +-----------------------------------------------------------------------+

if( !defined("PHPWG_ROOT_PATH") )
{
  die ("Hacking attempt!");
}

include_once(PHPWG_ROOT_PATH.'admin/include/tabsheet.class.php');

if (isset($_GET['action']) and 'restore' == $_GET['action'])
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_WEBMASTER);
check_input_parameter('action', $_GET, false, '/^(delete|restore)$/');

// +-----------------------------------------------------------------------+
// | Functions                                                             |
// +-----------------------------------------------------------------------+

function cu_get_fs($path)
{
  global $conf;

  $fs = array();
  $subdirs = array();

  if (is_dir($path))
  {
    if ($contents = opendir($path))
    {
      while (($node = readdir($contents)) !== false)
      {
        if ($node == '.' or $node == '..') continue;

        if (is_file($path.'/'.$node))
        {
          $fs[ltrim(str_replace($conf['upload_dir'], '', $path.'/'.$node), '/')] = 1;
        }
        elseif (is_dir($path.'/'.$node))
        {
          $subdirs[] = $node;
        }
      }
    }
    closedir($contents);

    foreach ($subdirs as $subdir)
    {
      $tmp_fs = cu_get_fs($path.'/'.$subdir);
      $fs = array_merge($fs, $tmp_fs);
    }
  }
  return $fs;
}

function cu_get_available_derivative($path)
{
  global $conf;

  $sizes = array('xxlarge', 'xlarge', 'large', 'medium', 'small', 'xsmall', '2small');

  $filepath_wo_ext = get_filename_wo_extension($path);
  $extension = get_extension($path);

  $pattern = $conf['data_location'].'i/'.$filepath_wo_ext.'-%s.'.$extension;

  foreach ($sizes as $size)
  {
    $size_path = sprintf($pattern, substr($size, 0, 2));
    if (file_exists($size_path))
    {
      return array('size'=>$size, 'path'=>$size_path);
    }
  }

  return null;
}

// +-----------------------------------------------------------------------+
// | Tabs                                                                  |
// +-----------------------------------------------------------------------+

$tabs = array(
  array(
    'code' => 'check',
    'label' => 'Check uploads',
    ),
  );

$tab_codes = array_map(
  function ($a) { return $a["code"]; },
  $tabs
  );

if (isset($_GET['tab']) and in_array($_GET['tab'], $tab_codes))
{
  $page['tab'] = $_GET['tab'];
}
else
{
  $page['tab'] = $tabs[0]['code'];
}

$tabsheet = new tabsheet();
foreach ($tabs as $tab)
{
  $tabsheet->add(
    $tab['code'],
    $tab['label'],
    'admin.php?page=plugin-check_uploads-'.$tab['code']
    );
}
$tabsheet->select($page['tab']);
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// | Perform checks and action                                             |
// +-----------------------------------------------------------------------+

if (isset($_POST['submit']) or isset($_GET['action']))
{
  // first we load paths from DB (database)
  $db_paths = array();

  $query = '
SELECT
    id,
    path,
    representative_ext
  FROM '.IMAGES_TABLE.'
;';

  // clean the list
  foreach (query2array($query) as $image)
  {
    if (strpos($image['path'], $conf['upload_dir']) === 0)
    {
      // echo '$image[path] = '.$image['path'].'<br>';
      $path = str_replace($conf['upload_dir'], '', $image['path']);
      $db_paths[ltrim($path, '/')] = $image['id'];

      if (isset($image['representative_ext']))
      {
        $filname_wo_ext = get_filename_wo_extension(basename($image['path']));
        $rep_dir = dirname($path).'/pwg_representative';
        $rep_path = $rep_dir.'/'.$filname_wo_ext.'.'.$image['representative_ext'];
        $db_paths[ltrim($rep_path, '/')] = $image['id'];
      }
    }
  }

  // echo '<pre>$db_paths = '; print_r($db_paths); echo '</pre>';

  // second, we load paths from FS (filesystem)
  $fs_paths = cu_get_fs($conf['upload_dir']);
  // echo '<pre>$fs_paths = '; print_r($fs_paths); echo '</pre>';

  // now, let's find unexpected files
  $nb_unexpected = 0;
  $nb_bad_checksum = 0;
  $nb_deleted = 0;

  foreach (array_keys($fs_paths) as $path)
  {
    if (!isset($db_paths[$path]))
    {
      if (basename($path) === 'index.htm')
      {
        if ('89e8a208e5f06c65e6448ddeb40ad879' != md5(file_get_contents($conf['upload_dir'].'/'.$path)))
        {
          array_push($page['errors'], $path.' unexpected checksum');
          $nb_bad_checksum++;
        }
        continue;
      }

      if (isset($_GET['action']) and 'delete' == $_GET['action'])
      {
        if (!unlink($conf['upload_dir'].'/'.$path))
        {
          trigger_error('"'.$path.'" cannot be removed', E_USER_WARNING);
          break;
        }
        $nb_deleted++;
      }
      else
      {
        array_push($page['errors'], $path.' is not in the database');
        $nb_unexpected++;
      }
    }
  }

  // now let's check if some files listed in the database are not available in the filesystem
  $nb_missing = 0;
  $nb_restorable = 0;
  $nb_restored = 0;

  foreach ($db_paths as $path => $image_id)
  {
    if (!isset($fs_paths[$path]))
    {
      $is_restored = false;
      $message = l10n('photo #%u path=%s is in the database, not in the filesystem', $image_id, $path);

      $full_path = $conf['upload_dir'].'/'.$path;
      $available_derivative = cu_get_available_derivative($full_path);
      if (!empty($available_derivative))
      {
        if (isset($_GET['action']) and 'restore' == $_GET['action'])
        {
          prepare_directory(dirname($full_path));
          rename($available_derivative['path'], $full_path);
          // echo 'rename('.$available_derivative['path'].', '.$full_path.');<br>';
          $nb_restored++;
          $is_restored = true;
        }
        else
        {
          $nb_restorable++;
          $message .= ' (size "'.$available_derivative['size'].'" available in data cache)';
        }
      }

      if (!$is_restored)
      {
        array_push($page['errors'], $message);
        $nb_missing++;
      }
    }
  }

  if ($nb_missing > 0)
  {
    array_unshift($page['errors'], $nb_missing.' files are listed in the database, but not available in the filesystem');

    if ($nb_restorable > 0)
    {
      array_unshift(
        $page['errors'],
        str_replace(
          'a href',
          'a class="icon-cw" href',
          l10n(
            '%d restorable photos from sizes cache. Quality is lower than original files. Prefer restoring from a backup. <a href="%s">Restore from sizes cache</a>',
            $nb_restorable,
            'admin.php?page=plugin-check_uploads&amp;action=restore'
          )
        )
      );
    }
  }

  if ($nb_bad_checksum > 0)
  {
    array_unshift($page['errors'], $nb_bad_checksum.' index.htm files with unexpected checksum');
  }

  if ($nb_unexpected > 0)
  {
    array_unshift(
      $page['errors'],
      str_replace(
        'a href',
        'a class="icon-trash" href',
        l10n(
          '%d unexpected files <a href="%s">delete them all</a>',
          $nb_unexpected,
          'admin.php?page=plugin-check_uploads&amp;action=delete'
          )
        )
      );
  }

  if ($nb_deleted > 0)
  {
    array_unshift(
      $page['infos'],
      l10n('%d files deleted', $nb_deleted)
      );
  }

  if ($nb_restored > 0)
  {
    array_unshift(
      $page['infos'],
      l10n('%d files restored (now please, synchronize metadata with the Batch Manager to update width/height/filesize)', $nb_restored)
      );
  }

  if (count($page['errors']) == 0)
  {
    array_push(
      $page['infos'],
      'Well done! Everything seems good :-)'
      );
  }
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(
  array(
    'plugin_admin_content' => dirname(__FILE__).'/admin.tpl'
    )
  );

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
?>
