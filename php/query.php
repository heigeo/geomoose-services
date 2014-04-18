<?php
/*Copyright (c) 2009-2012, Dan "Ducky" Little & GeoMOOSE.org

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.*/

/*
 * GeoMOOSE 2 Query Service
 */

include('output.php');

class Comparitor {
	protected $p = array();

	public function __construct($msFormat, $sqlFormat) {
		$this->p['ms'] = $msFormat;
		$this->p['sql'] = $sqlFormat;
	}

	public function toMapServer($field_name, $value) {
		return sprintf($this->p['ms'], strtoupper($field_name), $value);
	}

	public function toSQL($field_name, $value) {
		return sprintf($this->p['sql'], $field_name, $value);
	}
}

class Operator {
	protected $ms_format = "";
	protected $sql_format = "";

	public function __construct($msFormat, $sqlFormat) {
		$this->ms_format = $msFormat;
		$this->sql_format = $sqlFormat;
	}

	public function toMapServer($v) {
		return sprintf($this->ms_format, $v);
	}

	public function toSQL($v) {
		return sprintf($this->sql_format, $v);
	}
}

#
# In is "special" and requires a dedicated class
# Mostly, to deal with the fact that value = an array and
# different datasets will want to deal with the delimiter
# in a variable fashion. Frankly, we need more SQL injection filtering.
#
class InComparitor {
	protected $p = array();
	public function __construct() {
		$this->p['delim'] = ';';
	}

	public function setDelim($d) {
		$this->p['delim'] = $d;
	}

	public function convert_value($value, $out_delim) {
		return implode($out_delim, explode($this->p['delim'], $value));
	}

	public function toMapServer($field_name, $value) {
		return sprintf('"[%s]" in "%s"', $field_name, $this->convert_value($value, ","));
	}

	public function toSQL($field_name, $value) {
		return sprintf("%s in ('%s')", $field_name, $this->convert_value($value, "','"));
	}
}

$comparitors = array();
# string specific operations
# mapserver doesn't quite honor this the way I'd like it to but at the very least,
# the SQL databases will support it.
$comparitors['eq-str'] = new Comparitor('"[%s]" == "%s"', "%s = '%s'");
$comparitors['like'] = new Comparitor('"[%s]" =~ /.*%s.*/', "%s like '%%%s%%'");
$comparitors['left-like'] = new Comparitor('"[%s]" =~ /.*%s/', "%s like '%%%s'");
$comparitors['right-like'] = new Comparitor('"[%s]" =~ /%s.*/', "%s like '%s%%'");
$comparitors['like-icase'] = new Comparitor('"[%s]" ~* "%s"', "upper(%s) like '%%'||upper('%s')||'%%'");
$comparitors['left-like-icase'] = new Comparitor('"[%s]" ~* "%s$"', "%s like '%%'||upper('%s')");
$comparitors['right-like-icase'] = new Comparitor('"[%s]" ~* "^%s"', "%s like upper('%s')||'%%'");

# all other types
$comparitors['eq'] = new Comparitor('[%s] == %s', "%s = %s");
$comparitors['ge'] = new Comparitor('[%s] >= %s', '%s >= %s');
$comparitors['gt'] = new Comparitor('[%s] > %s', '%s > %s');
$comparitors['le'] = new Comparitor('[%s] <= %s', '%s <= %s');
$comparitors['lt'] = new Comparitor('[%s] < %s', '%s < %s');

$comparitors['in'] = new InComparitor();

$operators = array();

# MS, SQL formats
# this is probably a little redundant but C'est la Vie.
$operators['init'] = new Operator('(%s)', '%s');
$operators['and'] = new Operator('AND (%s)', 'and %s');
$operators['or'] = new Operator('OR (%s)', 'or %s');
$operators['nand'] = new Operator('AND (NOT (%s))', 'and not (%s)');
$operators['nor'] = new Operator('OR (NOT (%s))', 'or not (%s)');

class Predicate {
	protected $self = array();

	/*
	 * field_name = Field Name to search
	 * value = value to test against
	 * operator = operator class
	 * comparitor = comparitor class
	 * blank_okay (boolean) = set whether or not a blank value should be evaluated
	 */

	public function __construct($layer, $field_name, $value, $operator, $comparitor, $blank_okay = true) {
		$this->self['layer'] = $layer;
		$this->self['fname'] = $field_name;
		$this->self['val'] = $value;
		$this->self['op'] = $operator;
		$this->self['comp'] = $comparitor;
		$this->self['blank'] = $blank_okay;
	}

	public function getLayer() {
		return $this->self['layer'];
	}

	public function toMapServer() {
		if(((string)$this->self['val'] == '') and $this->self['blank']) {
			return '';
		}
		return $this->self['op']->toMapServer($this->self['comp']->toMapServer($this->self['fname'], $this->self['val']));
	}

	public function toSQL() {
		return $this->self['op']->toSQL($this->self['comp']->toSQL($this->self['fname'], $this->self['val']));
	}
}

$predicates = array();

# layers to search
$query_layers = array();
$query_layers[0] = urldecode(get_request_icase('layer0'));

# this will check to see which template format should be used
# query/itemquery/select/popup/etc.
$query_templates = array();
$query_templates[0] = urldecode(get_request_icase('template0'));

if($DEBUG) {
	error_log("Got parameters.<br/>");
}

# get set of predicates
# I've only allowed for 255 right now... people will have to deal with this
for($i = 0; $i < 255; $i++) {
	if(isset_icase('operator'.$i) or urldecode(get_request_icase('operator'.$i)) != NULL or $i == 0) {
		# see if the layer is different
		$layer = $query_layers[0];
		if(isset_icase('layer'.$i)) {
			$layer = urldecode(get_request_icase('layer'.$i));
		}

		$template = $query_templates[0];
		if(isset_icase('template'.$i)) {
			$template = urldecode(get_request_icase('template'.$i));
		}

		if(!in_array($layer, $query_layers) and $i > 0) {
			$query_layers[] = $layer;
			$query_templates[] = $template;
		}
		# check the opeartor
		$operator = false; $comparitor = false;

		if($i == 0) {
			$operator = $operators['init'];
		} else if(isset_icase('operator'.$i) and $operators[urldecode(get_request_icase('operator'.$i))]) {
			$operator = $operators[urldecode(get_request_icase('operator'.$i))];
		} else {
			# return error saying no valid operator found
		}

		if(isset_icase('comparitor'.$i) and $comparitors[urldecode(get_request_icase('comparitor'.$i))]) {
			$comparitor = $comparitors[urldecode(get_request_icase('comparitor'.$i))];
		} else {
			# return error saying there is no valid comparitor
		}

		$blank_okay = true;
		if(isset_icase('blanks'.$i) and strtolower(urldecode(get_request_icase('blanks'.$i))) == 'false') {
			$blank_okay = false;
		}

		# if a value is not set for subsequent inputs, use the first input
		# this allows queries to permeate across multiple layers
		if(isset_icase('value'.$i)) {
			$value = urldecode(get_request_icase('value'.$i));
			$p = new Predicate($layer, urldecode(get_request_icase('fieldname'.$i)), $value, $operator, $comparitor, $blank_okay);
			$predicates[] = $p;
		}
	}
}

if($DEBUG) {
	error_log("Parsed.<br/>");
}

#
# Iterate through the layers and build the results set.
#

# Load the mapbook
$mapbook = getMapbook();
$msXML = $mapbook->getElementsByTagName('map-source');

# content stores the HTML results
$content = '';
$totalResults = 0;

# store the features so we can render a map later
$resultFeatures = array();

# These are all the connection types, we ID the ones to be used as SQL versus MS regular expressions
# MS_INLINE, MS_SHAPEFILE, MS_TILED_SHAPEFILE, MS_SDE, MS_OGR, MS_TILED_OGR, MS_POSTGIS, MS_WMS, MS_ORACLESPATIAL, MS_WFS, MS_GRATICULE, MS_MYGIS, MS_RASTER, MS_PLUGIN
$SQL_LAYER_TYPES = array(MS_POSTGIS, MS_ORACLESPATIAL);
$NOT_SUPPORTED = array(MS_INLINE, MS_SDE, MS_WMS, MS_WFS, MS_GRATICULE, MS_RASTER, MS_PLUGIN, MS_OGR);

for($la = 0; $la < sizeof($query_layers); $la++) {
	# get the layer.
	for($map_source_i = 0; $map_source_i < $msXML->length; $map_source_i++) {
		$node = $msXML->item($map_source_i);
		$layers = $node->getElementsByTagName('layer');
		for($l = 0; $l < $layers->length; $l++) {
			$layer = $layers->item($l);
			$layerName = $layer->getAttribute('name');
			$path = $node->getAttribute('name').'/'.$layerName;
			if($path == $query_layers[$la]) {
				$file = $node->getElementsByTagName('file')->item(0)->firstChild->nodeValue;
				# Okay, now it's time to cook
				if(substr($file,0,1) == '.') {
					$file = $CONFIGURATION['root'].$file;
				}
				$map = ms_newMapObj($file);

				# Create an array of query layers
				$queryLayers = array();
				if($layerName == 'all') {
					for($ml = 0; $ml < $map->numlayers; $ml++) {
						array_push($queryLayers, $map->getLayer($ml));
					}
				} else {
					# Turn on the specific layer
					array_push($queryLayers, $map->getLayerByName($layerName));
				}

				# Iterate through the queryLayers...
				foreach($queryLayers as $queryLayer) {
					$predicate_strings = array();
					$is_sql = in_array($queryLayer->connectiontype, $SQL_LAYER_TYPES);
					for($i = 0; $i < sizeof($predicates); $i++) {
						if($predicates[$i]->getLayer() == $query_layers[$la]) {
							if($is_sql) {
								$predicate_strings[] = $predicates[$i]->toSQL();
							} else {
								$predicate_strings[] = $predicates[$i]->toMapServer();
							}
						}
					}
					# the filter string.
					$filter_string = implode(' ', $predicate_strings);

					# diag message
					if($DEBUG) {
	 				  error_log( 'Search Layer: '.$query_layers[$la].' Template: '.$query_templates[$la].' FILTER: '.$filter_string);
					  error_log( $is_sql);
					  error_log( $queryLayer->getMetaData($query_templates[$la]));
					}

					$queryLayer->set('status', MS_DEFAULT);
					
					if($filter_string) {
						# WARNING! This will clobber existing filters on a layer.
						if($is_sql) {
							$queryLayer->setFilter($filter_string);
						} else {
							$queryLayer->setFilter('('.$filter_string.')');
						}
					}		
					$selectMap = explode("/", str_replace("./","",$file), -1);
					$selectMap = implode("/", $selectMap);
					$template = implode('', file($selectMap . "/" . $queryLayer->getMetadata($query_templates[$la])));
					
					$queryLayer->set('template', $queryLayer->getMetaData($query_templates[$la]));
					$results = "";
					
					$ext = $queryLayer->getExtent();
					if($DEBUG) {
						error_log(implode(',', array($ext->minx,$ext->miny,$ext->maxx,$ext->maxy)));
						error_log("<br/>extent'd.<br/>");
					}

					$queryLayer->open();
					$queryLayer->queryByRect($ext);
					$results = $map->processQueryTemplate(array(), false);
					
					$numResults = 0;

					$projectionMap = $map->getProjection();
					if($queryLayer->getProjection() != NULL) {
						$projectionMap = $queryLayer->getProjection();
					}
					if($projectionMap != NULL) {
						# reproject the query shape as available.
						$projectionMap = ms_newProjectionObj($projectionMap);
					}
					
					for($i = 0; $i < $queryLayer->getNumResults(); $i++) {
						$shape = $queryLayer->getShape($queryLayer->getResult($i));
						if($projectionMap) {
							$shape->project($projectionMap, ms_newprojectionobj($projection));
						} else {
							$shape->project($LATLONG_PROJ, ms_newprojectionobj($projection));
						}
						$numResults += 1;
						$shape->set("text", $i);
						$resultFeatures[] = $shape;
						$results = internalIdTemplate($results, $i, $template);
					}

					$totalResults += $numResults;
					if($DEBUG) { error_log('Total Results: '.$numResults); }
					
					#Set header/footer
					if($queryLayer->getMetadata('itemquery_header')) {
						$queryLayer->set('header', $queryLayer->getMetadata('itemquery_header'));
						$headerArray = implode('', file($selectMap . "/" . $queryLayer->getMetadata('itemquery_header')));
						$results = processTemplate($headerArray, $dict) . $results;
					}
					if($queryLayer->getMetadata('itemquery_footer')) {
						$queryLayer->set('footer', $queryLayer->getMetadata('itemquery_footer'));
						$footerArray = implode('', file($selectMap . "/" . $queryLayer->getMetadata('itemquery_footer')));
						$footer = processTemplate($footerArray, $dict);
					}
					
					if($DEBUG) { error_log('Results from MS: '.$results); }
					$content = $content . $results;
				}
			}
		}
	}
}

# Array to hold values needed inside output and map file
$dict = array();
$dict['UNIQUEID'] = 'select_'.getmypid().time();
$dict['SHAPEPATH'] = $CONFIGURATION['temp'];
$dict['PROJECTION'] = 'EPSG:4326'; 
$dict['foundShapes'] = $totalResults;
$content = processTemplate($content, $dict);
$dict['MAP_PROJECTION'] = $projection; 
$dict['results'] = $content;
$dict['footer'] = $footer;
$dict['foundShapesArray'] = $resultFeatures;
$dict["fileName"] = basename(__FILE__, '.php');
	
# Get the type of query to return
switch(strtoupper(urldecode(get_request_icase('type')))) {
	case "WMSDATABASE":
		outputDatabase($dict, "WMS");
		break;
	case "WFS":
		outputDatabase($dict, "WFS");
		break;
	case "HTML":
		outputHTML($dict);
		break;
	case "WMSMEMORY":
	default:
		outputMemory($dict);
		break;
}
?>