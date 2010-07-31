<?php
/*
Plugin Name: Category list
Plugin URI: http://enanocms.org/
Description: A simple parser hook to display the contents of a category within another page. Syntax is {{CategoryContents:<cat name>|sub=(on|off)|pages=(on|off)}}. (Both sub [subcategories] and pages default to on)
Author: Dan Fuhry
Version: 1.0
Author URI: http://enanocms.org/
*/

// attach parser hook
$plugins->attachHook('render_wikiformat_veryearly', 'catlist_parser_hook($text);');

function catlist_parser_hook(&$text)
{
  if ( preg_match_all('/\{\{
                         CategoryContents:
                         ([^|\r\n\a\t]+?)                                              # category name
                         (\|(?:(?:[a-z0-9_]+)(?:[\s]*)=(?:[\s]*)(?:[^\}\r\n\a\t]+)))?  # parameters
                       \}\}/x', $text, $matches) )
  {
    foreach ( $matches[0] as $i => $match )
    {
      $cat_name =& $matches[1][$i];
      $params =& $matches[2][$i];
      $params = catlist_parse_params($params);
      
      $do_subs = ( isset($params['sub']) && $params['sub'] === 'off' ) ? false : true;
      $do_pages = ( isset($params['pages']) && $params['pages'] === 'off' ) ? false : true;
      
      $result = catlist_print_category($cat_name, $do_subs, $do_pages);
      $text = str_replace($match, $result, $text);
    }
  }
}

function catlist_parse_params($params)
{
  $params = trim($params, '|');
  $params = explode('|', $params);
  $return = array();
  foreach ( $params as $val )
  {
    if ( preg_match('/^([a-z0-9_]+)(?:[\s]*)=(?:[\s]*)([^\}\r\n\a\t]+)$/', $val, $match) )
    {
      $return[ $match[1] ] = $match[2];
    }
  }
  return $return;
}

function catlist_print_category($cat_name, $do_subs, $do_pages)
{
  // nicely format and print the category out, then return HTML
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  // make sane the category ID
  $cat_id = $db->escape(sanitize_page_id($cat_name));
  
  // if we're doing both, use the more complicated query
  if ( $do_subs && $do_pages )
  {
    
    $q = $db->sql_query('SELECT c.page_id, c.namespace, p.name, ( c.namespace = \'Category\' ) AS is_subcategory FROM ' . table_prefix . "categories AS c\n"
                      . "  LEFT JOIN " . table_prefix . "pages AS p\n"
                      . "    ON ( p.urlname = c.page_id AND p.namespace = c.namespace )\n"
                      . "  WHERE c.category_id = '$cat_id'\n"
                      . "  ORDER BY is_subcategory DESC;");
  }
  else
  {
    // nice little where clause...
    if ( $do_subs && !$do_pages )
    {
      $where = 'c.namespace = \'Category\'';
    }
    else if ( $do_pages && !$do_subs )
    {
      $where = 'c.namespace != \'Category\'';
    }
    else
    {
      // ok, subs = off AND pages = off. some people are dummies.
      return '';
    }
    $q = $db->sql_query('SELECT c.page_id, c.namespace, p.name, ( c.namespace = \'Category\' ) AS is_subcategory FROM ' . table_prefix . "categories AS c\n"
                      . "  LEFT JOIN " . table_prefix . "pages AS p\n"
                      . "    ON ( p.urlname = c.page_id AND p.namespace = c.namespace )\n"
                      . "  WHERE c.category_id = '$cat_id' AND $where\n"
                      . "  ORDER BY is_subcategory DESC;");
  }
  if ( !$q )
    $db->_die();
  
  $html = '';
  if ( $do_subs && $do_pages )
  {
    $html .= '<h3>Subcategories</h3>';
  }
  if ( $do_subs )
  {
    // LIST: subcategories
    $html .= '<div class="tblholder">';
    $html .= '<table border="0" cellspacing="1" cellpadding="4">';
    $html .= '<tr>';
    $ticker = 0;
    $have_subcats = false;
    $class = 'row1';
    while ( $row = $db->fetchrow($q) )
    {
      if ( empty($row['is_subcategory']) )
        break;
      
      $have_subcats = true;
      
      if ( $ticker == 3 )
      {
        $ticker = 0;
        $html .= '</tr><tr>';
        $class = ( $class == 'row1' ) ? 'row2' : 'row1';
      }
      $ticker++;
      
      $inner = '<a href="' . makeUrlNS($row['namespace'], $row['page_id'], false, true) . '">' . htmlspecialchars($row['name']) . '</a>';
      $html .= '<td style="width: 33.3%;" class="' . $class . '">' . $inner . '</td>';
    }
    if ( !$have_subcats )
    {
      $ticker++;
      $html .= '<td style="width: 33.3%;" class="' . $class . '">No subcategories.</td>';
    }
    // fill in the rest of the table
    while ( $ticker < 3 )
    {
      $ticker++;
      $html .= '<td style="width: 33.3%;" class="' . $class . '"></td>';
    }
    $html .= '</tr>';
    $html .= '</table></div>';
  }
  if ( $do_subs && $do_pages )
  {
    $html .= '<h3>Pages</h3>';
  }
  if ( $do_pages )
  {
    // LIST: member pages
    $html .= '<div class="tblholder">';
    $html .= '<table border="0" cellspacing="1" cellpadding="4">';
    $html .= '<tr>';
    $ticker = 0;
    $have_pages = false;
    $class = 'row1';
    // using do-while because the last row was already fetched if we had to do subcategories
    do
    {
      if ( !$do_subs && !isset($row) )
        $row = $db->fetchrow();
      
      if ( !$row )
        break;
      
      $have_pages = true;
      
      if ( $ticker == 3 )
      {
        $ticker = 0;
        $html .= '</tr><tr>';
        $class = ( $class == 'row1' ) ? 'row2' : 'row1';
      }
      $ticker++;
      
      $inner = '<a href="' . makeUrlNS($row['namespace'], $row['page_id'], false, true) . '">' . htmlspecialchars($row['name']) . '</a>';
      $html .= '<td style="width: 33.3%;" class="' . $class . '">' . $inner . '</td>';
    }
    while ( $row = $db->fetchrow() );
    
    if ( !$have_pages )
    {
      $ticker++;
      $html .= '<td style="width: 33.3%;" class="' . $class . '">No pages in this category.</td>';
    }
    // fill in the rest of the table
    while ( $ticker < 3 )
    {
      $ticker++;
      $html .= '<td style="width: 33.3%;" class="' . $class . '"></td>';
    }
    $html .= '</tr>';
    $html .= '</table></div>';
  }
  return $html;
}
