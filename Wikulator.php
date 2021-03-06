<?php
/*
Plugin Name: Wikulator
Plugin URI: http://enanocms.org/plugin/wikulator
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

$plugins->attachHook('render_wikiformat_posttemplates', 'mediafier_draw_toc($text);');
$plugins->attachHook('render_wikiformat_post', 'mediafy($result);');
$plugins->attachHook('compile_template', 'mediafier_add_headers();');
$plugins->attachHook('html_attribute_whitelist', '$whitelist["ref"] = array(); $whitelist["references"] = array("/");');

function mediafy(&$text)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  mediafy_highlight_search_words($text);
  mediafy_process_references($text);
}

function mediafier_draw_toc(&$text)
{
	static $heading_names = array();
	
  if ( strstr($text, '__NOTOC__') )
    return true;
  
  if ( !preg_match_all('/^\s*([=]{1,6})([^\r\n]+)\\1\s*$/m', $text, $matches) )
    return true;
  
  $heading_map = array();
  foreach ( $matches[1] as $heading )
  {
    $heading_map[] = strlen($heading);
  }
  
  if ( count($heading_map) < 4 && !strstr($text, '__TOC__') )
    return true;
  
  $prev = 0;
  $levels = 0;
  $treenum = array();
  $toc = "\n";
  foreach ( $heading_map as $i => $head )
  {
  	// $head = level of the heading (1-6)
  	  
  	if ( $head > $prev )
  	{
  		// deeper heading than the previous; indent
  		$toc .= "\n    <dl>\n  ";
  		$levels++;
  		$treenum[] = 0;
  	}
  	else if ( $head < $prev )
  	{
  		// shallower heading than the previous; go up by one
  		$toc .= "</dd></dl></dd>\n  ";
  		$levels--;
  		array_pop($treenum);
  	}
  	else
  	{
  		// same as previous; terminate it
  		$toc .= "</dd>\n  ";
  	}
  	
    $treenum = array_values($treenum);
    if ( isset($treenum[count($treenum)-1]) )
      $treenum[count($treenum)-1]++;
  	
    if ( version_compare(enano_version(), '1.1.7', '>=') )
    {
		$tocid = sanitize_page_id(trim($matches[2][$i]));
		$tocid = str_replace(array('[', ']'), '', $tocid);
		
		// conflict avoidance
		if ( isset($heading_names[$tocid]) )
		{
			$id = 2;
			while ( isset($heading_names["{$tocid}{$id}"]) )
				$id++;
			
			$tocid .= $id;
		}
		$heading_names[$tocid] = true;
	}
	else
	{
		$tocid = "$i";
	}
	
	$toc .= '<dd><a href="#head:' . $tocid . '">' . implode('.', $treenum) . ' ' . htmlspecialchars($matches[2][$i]) . "</a>";
	
	$prev = $head;
  }
  // and at the end of the loop...
  $toc .= "</dd>\n";
  while ( $levels > 1 )
  {
  	  $toc .= "</dl></dd>\n";
  	  $levels--;
  }
  $toc .= "</dl>\n";
  
  $toc_body = "<nowiki><div class=\"toc mdg-comment\">
                <dl><dd><b>Contents</b> <small>[<a href=\"#\" onclick=\"collapseTOC(this); return false;\">hide</a>]</small></dd></dl>
                <div>$toc</div>
              </div></nowiki>";
    
  if ( strstr($text, '__TOC__') )
  {
    $text = str_replace_once('__TOC__', $toc_body, $text);
  }
  else if ( $text === ($rtext = preg_replace('/^=/', "$toc_body\n\n=", $text)) )
  {
    $text = str_replace_once("\n=", "\n$toc_body\n=", $text);
  }
  else
  {
    $text = $rtext;
    unset($rtext);
  }
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
      <style type="text/css">
      div.toc {
        display: table;
        max-width: 70%;
        padding: 0.7em 1.7em 0.7em 0.7em;
        margin: 10px 0 0 0;
      }
      div.toc dl {
        margin: 2px 0;
      }
      div.toc dd {
        margin-left: 1em;
      }
      </style>
      <script type="text/javascript">
      // <![CDATA[
        function refsOff()
        {
          var divs = getElementsByClassName(document, '*', 'refbottom');
          for ( var i in divs )
          {
            \$dynano(divs[i]).rmClass('refbak');
          }
          divs = getElementsByClassName(document, '*', 'reftop');
          for ( var i in divs )
          {
            \$dynano(divs[i]).rmClass('refbak');
          }
        }
        function refToBottom(id)
        {
          refsOff();
          \$dynano('ref_'+id+'_b').addClass('refbak');
        }
        function refToTop(id)
        {
          refsOff();
          \$dynano('cite_'+id).addClass('refbak');
        }
        function collapseTOC(el)
        {
          var toc_inner = el.parentNode.parentNode.parentNode.parentNode.getElementsByTagName('div')[0];
          if ( toc_inner.style.display == 'none' )
          {
            el.innerHTML = 'hide';
            toc_inner.style.display = 'block';
          }
          else
          {
            el.innerHTML = 'show';
            toc_inner.style.display = 'none';
          }
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
    $result = preg_replace('/([\W]|^)(' . str_replace('/', '\/', preg_quote($word)) . ')([\W])/i', "\\1<span class=\"highlight\">\\2</span>\\3", $result);
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
    $ref = trim($ref);
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
