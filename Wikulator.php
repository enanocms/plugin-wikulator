<?php
/*
Plugin Name: Mediafier
Plugin URI: http://enanocms.org/Mediafier
Description: Several parser extensions that provide MediaWiki-like support for references, search highlighting, and a table of contents to Enano
Author: Dan Fuhry
Version: 0.1 beta 1
Author URI: http://enanocms.org/
*/

/*
 * This program is Free Software; you can redistribute and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for details.
 */

$plugins->attachHook('render_wikiformat_post', 'mediafy($result);');
$plugins->attachHook('compile_template', 'mediafier_add_headers();');
$plugins->attachHook('html_attribute_whitelist', '$whitelist["ref"] = array(); $whitelist["references"] = array("/");');

function mediafy(&$text)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  mediafy_highlight_search_words($text);
  mediafy_process_references($text);
}

function mediafier_add_headers()
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  $template->add_header("<style type=\"text/css\">
                        .highlight  { background-color: #FFFFC0; font-weight: bold; }
                        .refbak     { background-color: #E0E0FF; font-weight: bold; }
                        .references { font-size: smaller; }
                      </style>");
  $ref_script = <<<EOF
      <enano:no-opt>
      <script type="text/javascript">
      // <![CDATA[
        function refsOff()
        {
          var divs = getElementsByClassName(document, '*', 'refbottom');
          for ( var i in divs )
          {
            $(divs[i]).rmClass('refbak');
          }
          divs = getElementsByClassName(document, '*', 'reftop');
          for ( var i in divs )
          {
            $(divs[i]).rmClass('refbak');
          }
        }
        function refToBottom(id)
        {
          refsOff();
          $('ref_'+id+'_b').addClass('refbak');
        }
        function refToTop(id)
        {
          refsOff();
          $('cite_'+id).addClass('refbak');
        }
        // ]]>
      </script>
      </enano:no-opt>

EOF;
  $template->add_header($ref_script);
}

function mediafy_highlight_search_words(&$result)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  if ( !isset($_SERVER['HTTP_REFERER']) && !isset($_GET['highlight']) )
    return false;
  
  $referer = ( isset($_GET['highlight']) ) ? $_SERVER['REQUEST_URI'] : $_SERVER['HTTP_REFERER'];
  $term = ( isset($_GET['highlight']) ) ? 'highlight' : 'q';
  
  preg_match('/(\?|&)'.$term.'=(.+?)(&|$)/', $referer, $match);
  if ( !isset($match[2]) )
  {
    return false;
  }
  
  $words = $match[2];
  $words = urldecode($words);
  if ( $term == 'q' )
  {
    // it's from a search query - extract terms
    require_once(ENANO_ROOT . '/includes/search.php');
    $words = parse_search_query($words, $warnings);
    $words = array_merge($words['any'], $words['req']);
  }
  else
  {
    $words = explode(' ', $words);
  }
  
  // strip HTML out of the rendered text
  $rand_seed = $session->dss_rand();
  preg_match_all('/<.+?>/', $result, $html_matches);
  $i = 0;
  foreach ( $html_matches[0] as $match )
  {
    $i++;
    $result = str_replace($match, "{HIGHLIGHT_PLACEHOLDER:$i:$rand_seed}", $result);
  }
  
  // highlight matches
  foreach ( $words as $word )
  {
    $result = preg_replace('/([\W]|^)(' . preg_quote($word) . ')([\W])/i', "\\1<span class=\"highlight\">\\2</span>\\3", $result);
  }
  
  // restore HTML
  $i = 0;
  foreach ( $html_matches[0] as $match )
  {
    $i++;
    $result = str_replace("{HIGHLIGHT_PLACEHOLDER:$i:$rand_seed}", $match, $result);
  }
  
  // add "remove highlighting" link
  $result = '<div style="float: right; text-align: right;"><a href="' . makeUrl($paths->page) . '" onclick="ajaxReset(); return false;">Turn off <span class="highlight">highlighting</span></a></div>' .
            $result;
  
}

function mediafy_process_references(&$text)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  // Is there a <references /> in the wikitext? If not, just return out to avoid empty processing
  if ( !preg_match('#<references(([ ]*)/)?>#', $text) )
  {
    //die('no match');
    return false;
  }
  
  // Retrieve references
  preg_match_all('#<ref>(.+?)</ref>#s', $text, $matches);
  
  // Init counter
  $i = 0;
  
  // Init refs array
  $refs = array();
  
  // main parser loop
  foreach ( $matches[0] as $j => $match )
  {
    $i++;
    $inner =& $matches[1][$j];
    $refs[$i] = $inner;
    $reflink = '<sup><a class="reftop" id="cite_' . $i . '" name="cite_' . $i . '" href="#ref_' . $i . '" onclick="refToBottom(\'' . $i . '\');">[' . $i . ']</a></sup>';
    $text = str_replace($match, $reflink, $text);
  }
  
  // compile refs div
  $refsdiv = '<div class="references">';
  $refsdiv .= '<table border="0" width="100%"><tr><td valign="top">';
  $count = ( count($refs) >= 20 ) ? floor(count($refs) / 2) : 99;
  foreach ( $refs as $i => $ref )
  {
    $reflink = '<span id="ref_' . $i . '" name="ref_' . $i . '"><sup><b><a onclick="refToTop(\'' . $i . '\');" href="#cite_' . $i . '">^</a></b></sup> </span>';
    $refsdiv .= "<div class=\"refbottom\" id=\"ref_{$i}_b\">$reflink $i. $ref</div>";
    if ( $i == $count )
      $refsdiv .= '</td><td valign="top">';
  }
  $refsdiv .= '</td></tr></table>';
  $refsdiv .= '</div>';
  
  preg_match('#<references(([ ]*)/)?>#', $text, $match);
  $text = str_replace_once($match[0], $refsdiv, $text);
}

?>
