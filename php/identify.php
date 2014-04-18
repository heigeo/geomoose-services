<?php
/*Copyright (c) 2009, Dan "Ducky" Little & GeoMOOSE.org

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
 * File: identify.php
 * Provides drill-down identify functionality.
 */

include('output.php');

# Get the Query Shape
$shape = get_request_icase('shape');

# Set up the list of potential layers to identify
if (get_request_icase('visible_layers') != "") {
	$visibleLayers = urldecode(get_request_icase('visible_layers'));
} else {
	$visibleLayers = urldecode(get_request_icase('layers'));
}

$hiddenLayers = urldecode(get_request_icase('hidden_layers'));
$layersList = explode(':', $visibleLayers);
$layersList = array_merge($layersList, explode(':', $hiddenLayers));
$layersList = array_unique($layersList);

# Load the mapbook
$mapbook = getMapbook();
$msXML = $mapbook->getElementsByTagName('map-source');

#Figure out the exact layers to identify
$layersToIdentify = array();
for($i = 0; $i < $msXML->length; $i++) {
	$node = $msXML->item($i);
	$file = $node->getElementsByTagName('file');
	$layers = $node->getElementsByTagName('layer');
	$msType = strtolower($node->getAttribute('type'));
	$queryable = false;
	if($node->hasAttribute('queryable')) {
		$queryable = parseBoolean($node->getAttribute('queryable'));
	} else {
		if($msType == 'mapserver') {
			$queryable = true;
		} else if($msType == 'wms') {
			$queryable = false;
		}
	}

	if($queryable) {
		for($l = 0; $l < $layers->length; $l++) {
			$layer = $layers->item($l);
			$path = $node->getAttribute('name').'/'.$layer->getAttribute('name');
			$identify = false;
			foreach($layersList as $ll) {
				if($path == $ll) {
					$identify = true;
				}
			}
			if($identify == true) {
				$nodeName = 'file';
				$params = '';
				if($msType == 'wms') {
					$nodeName = 'url';
					$paramElms = $node->getElementsByTagName('param');
					foreach($paramElms as $p) {
						$params = $params . '&' . $p->getAttribute('name') . '=' . $p->getAttribute('value');
					}
				}
				array_push($layersToIdentify, $msType.':'.$layer->getAttribute('name').':'.$node->getElementsByTagName($nodeName)->item(0)->nodeValue.$params);

			}
		}
	}
}
# Setup the Query Shape
$queryShape = ms_shapeObjFromWkt($shape);
$content = '';

#Set Lat/Lon
if($queryShape->type == MS_SHAPE_POINT) {
	$point = $queryShape->line(0)->point(0);
} else {
	$point = $queryShape->getCentroid();
}
	
# Needed WMS Information
$wmsBBOX = ($point->x - 100). ',' . ($point->y - 100) . ',' . ($point->x + 100) . ',' . ($point->y + 100);
$wmsHeaderTemplate = implode('', file($CONFIGURATION['wms_header']));
$wmsRecordTemplate = implode('', file($CONFIGURATION['wms_record']));
$wmsFooterTemplate = implode('', file($CONFIGURATION['wms_footer']));
$foundShapes = array();

foreach($layersToIdentify as $mf) {
	$info = explode(':', $mf);
	if($info[0] == 'mapserver') {
		$path = $info[2];
		if(substr($path,0,1) == '.') {
			$path = $CONFIGURATION['root'].$path;
		}
		$map = ms_newMapObj($path);		
		$q_shape = NULL;
		# This makes a slightly dangerous assumption, that if
		# you defined a map projection, the rest of the layers match.
		# Unfortunately, we query at the map level.  This is still a massive improvement
		# over <= 2.4 because before we ignored projection issues altogether.
		$map_proj = $map->getProjection();
		if($DEBUG) {
			print_r("Map Projection: ".$map_proj);
			print_r("Input Projection: ".$projection);
		}
		if($map_proj != NULL) {
			# turn it into a real projection object.
			$map_proj = ms_newprojectionobj($map_proj);
			# get the projection object from the CGI
			$shape_proj = ms_newprojectionobj($projection);
			# convert the shape into the appropriate coordinate system
			# using "reprojectWKT" from config.php
			$q_shape = ms_shapeObjFromWkt(reprojectWKT($queryShape->toWkt(), $shape_proj, $map_proj)); 
			if($DEBUG) {
				print_r('Input WKT: '.$queryShape->toWkt());
				print_r('Projected WKT: '.$q_shape->toWkt());
			}
		} else {
			$q_shape = $queryShape;
		}
		
		for($j = 0; $j < $map->numlayers; $j++) {
			$layer = $map->getLayer($j);
			if($info[1] == 'all' || $info[1] == $layer->name) {
				$layer->set('template', $layer->getMetadata('identify_record'));
				$layer->set('status', MS_DEFAULT);
				$layer->open();
				
				$max_features = $layer->getMetaData('identify_max_features');
				if($max_features)
					$layer->set('maxfeatures', $max_features);
				
				if($queryShape->type == MS_SHAPE_POINT) {
					$pointIn = $q_shape->line(0)->point(0);
					$layer->queryByPoint($pointIn, MS_MULTIPLE, -1);
				} else {
					$layer->queryByShape($q_shape);
				}
				$content = $content . $map->processQueryTemplate(array(), false);
				
				$selectMap = explode("/", str_replace("./","",$path), -1);
				$selectMap = implode("/", $selectMap);
				$template = implode('', file($selectMap . "/" . $layer->getMetadata('identify_record')));

				for ($i = 0; $i < ($layer->getNumResults()); $i++) {
					$shapeLayer = $layer->getShape($layer->getResult($i));
					if($map_proj != NULL)
						$shapeLayer->project($map_proj, ms_newprojectionobj($projection));
					$shapeLayer->set("text", $i);
					$foundShapes[] = $shapeLayer;
					$content = internalIdTemplate($content, $i, $template);
				}
			}
		}
	} else if($info[0] == 'wms') {
		$wmsUrl = $info[2].'&SERVICE=WMS&VERSION=1.1.0&REQUEST=GetFeatureInfo&WIDTH=100&HEIGHT=100&X=50&Y=50&EXCEPTIONS=application/vnd.ogc.se_xml&LAYERS='.$info[1].'&QUERY_LAYERS='.$info[1].'&BBOX='.$wmsBBOX.'&SRS='.$projection.'&STYLES=&INFO_FORMAT=application/vnd.ogc.gml';

		# Resolve the url if relative
		$firstCh = substr($wmsUrl, 0, 1);
		if($firstCh == '/') {
			$wmsUrl = 'http://localhost'.$wmsUrl;
		} else {
			# Return an error here once error handling has been created.
		}

		# Fetch the GML
		$curlHandle = curl_init($wmsUrl);
		curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, 1);
		$gml = curl_exec($curlHandle);
		curl_close($curlHandle);

		# Now Parse the GML according to the gml_template
		$results = '';
		$gmlDoc = new DOMDocument();
		$gmlDoc->loadXML($gml);

		$gmlLayers = $gmlDoc->documentElement->childNodes;
		foreach($gmlLayers as $layer) {
			if($layer->childNodes->length > 0) {
				foreach($layer->childNodes as $feature) {
					$splitPos = strpos($feature->tagName, '_feature');
					$featureDict = array();
					if(!($splitPos === false)) {
						$featureDict['FEATURE_TITLE'] = substr($feature->tagName, 0, $splitPos-1);
						$featureLines = array();
						$results = $results . processTemplate($wmsHeaderTemplate, $featureDict);
						foreach($feature->childNodes as $attr) {
							if(substr($attr->tagName, 0, 3) != 'gml') {
								$featureDict['NAME'] = str_replace('_', ' ', $attr->tagName);

								$featureDict['VALUE'] = $attr->firstChild->nodeValue;
								$results = $results . processTemplate($wmsRecordTemplate, $featureDict);
							}

						}
						$featureDict['NAME']  = '';
						$featureDict['VALUE'] = '';
						$results = $results . processTemplate($wmsFooterTemplate, $featureDict);

					}
				}
			}
		}
		$content = $content . $results;
	}
}

# Array to hold values needed inside output and map file
$dict = array();
$dict['UNIQUEID'] = 'select_'.getmypid().time();
$dict['SHAPE_WKT'] = $shape;
$dict['SHAPEPATH'] = $CONFIGURATION['temp'];
$dict['MAP_PROJECTION'] = $projection; 
$dict['PROJECTION'] = 'EPSG:4326'; 
$dict['foundShapes'] = sizeof($foundShapes);

if ($CONFIGURATION['use_latlong']) {
	$clonePoint = $point;
	$projOutObj = ms_newprojectionobj("proj=latlong");
	$projInObj = ms_newprojectionobj($projection);
	$clonePoint->project($projInObj, $projOutObj);
	$dict['mapx'] = round($clonePoint->x,4);
	$dict['mapy'] = round($clonePoint->y,4);
} else {
	$dict['mapx'] = round($point->x,4);
	$dict['mapy'] = round($point->y,4);
}
	
#Set header/footer
$headerArray = file($CONFIGURATION['identify_header']);
$headerContents = implode('', $headerArray);
$content = processTemplate($headerContents, $dict) . $content;
$footerArray = file($CONFIGURATION['identify_footer']);
$footerContents = implode('', $footerArray);
$dict['footer'] = processTemplate($footerContents, $dict);
		
$dict['results'] = $content;
$dict['queryShape'] = $queryShape;
$dict['foundShapesArray'] = $foundShapes;
$dict["fileName"] = basename(__FILE__, '.php');
	
# Get the type of query to return
switch(strtoupper(urldecode(get_request_icase('type')))) {
	case "WMSDATABASE":
		outputDatabase($dict, "WMS");
		break;
	case "WFS":
		outputDatabase($dict, "WFS");
		break;
	case "WMSMEMORY":
		outputMemory($dict);
		break;
	case "HTML":
	default:
		outputHTML($dict);
		break;
}
?>