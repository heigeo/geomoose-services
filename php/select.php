<?php
/*Copyright (c) 2009-2013, Dan "Ducky" Little & GeoMOOSE.org

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
 * GeoMOOSE 2 Select Service
 */

include('output.php');

# Setup the buffer values.
# ** ALL BUFFERS ASSUME METERS **
# "shape_buffer" is a buffer of the input shapes. 
$shape_buffer = 0;
if(array_key_exists('shape_buffer', $_REQUEST) and isset($_REQUEST['shape_buffer'])) {
	$shape_buffer = urldecode(get_request_icase('shape_buffer'));
}
	
# "selection_buffer" is a buffer the shapes selected by shape + shape_buffer
$selection_buffer = 0;
if(array_key_exists('selection_buffer', $_REQUEST) and isset($_REQUEST['selection_buffer'])) {
	$selection_buffer = urldecode(get_request_icase('selection_buffer'));
}

# Get the Query Shape
$shape_wkt = urldecode(get_request_icase('shape'));

# This is the layer where shapes are selected from
$selectLayer = urldecode(get_request_icase('select_layer'));

# This is the layer from where feature information is queried
$queryLayer = urldecode(get_request_icase('query_layer'));

# Load the mapbook
$mapbook = getMapbook();

# If the shape is not a polygon, then we'll make it one so the logic below makes more sense.
if(strtoupper(substr($shape_wkt,0,7)) != 'POLYGON' and $selection_buffer == 0)
	$selection_buffer = 0.0001;

# Create the selection shape
$selectShape = ms_shapeObjFromWkt($shape_wkt);

# Convert shape to wgs84 for internal uses.
$selectShape->project(ms_newprojectionobj($projection), $LATLONG_PROJ);

# Buffer the shape
$selectShape = saneBuffer($selectShape, NULL, $selection_buffer);

# $queryShapes is our global bucket for shapes against which we are going to query the selected layer.
$queryShapes = array();

# If we have a query layer from which to pull shapes, we'll do that.
if(isset($queryLayer) and $queryLayer != null and $queryLayer != '') {
	$queryMap = getMapfile($mapbook, $queryLayer);
	$layer = array_reverse(explode('/', $queryLayer));

	# Open the map.
	$map = ms_newMapObj($CONFIGURATION['root'].$queryMap);
	$layer = $map->getLayerByName($layer[0]);
	
	# Get it's projection.
	$map_proj = $map->getProjection();
	
	# Turn it into a real projection object if it's not null.
	if($map_proj != NULL)
		$map_proj = ms_newprojectionobj($map_proj);

	# Use the inheritance between map and layer projections to get the current layer's projection
	$layer_projection = $map_proj;
	if($layer->getProjection() != NULL)
		$layer_projection = ms_newprojectionobj($layer->getProjection());
	
	# Use WKT to create a copy of the shape object (which is in wgs84)
	$layer_query_shape = ms_shapeObjFromWkt($selectShape->toWkt());
	
	# Convert it to local coordinates.
	$layer_query_shape->project($LATLONG_PROJ, $layer_projection);

	# Setup a dummy template so mapscript will query against the layer.
	$layer->set('template','dummy.html');
	
	# Do the query.
	$layer->open();
	$layer->queryByShape($layer_query_shape);

	while($shape = $layer->nextShape()) {
		# okay, now we normalize these shapes to 4326, I need something normal just for a second.
		# add it to our querying stack for later.
		if($layer_query_shape->intersects($shape) == MS_TRUE or $shape->containsShape($layer_query_shape) == MS_TRUE) {
			if($layer_projection != NULL) {
				# convert the shape to wgs84 for internal use.
				$shape->project($layer_projection, $LATLONG_PROJ);
			}

			if($shape_buffer > 0) {
				$queryShapes[] = saneBuffer($shape, NULL, $shape_buffer, $DEBUG);
			} else {
				$queryShapes[] = $shape;
			}
		}
	}
	# close the layer up, we're done with it.
	$layer->close();
}

# Build a massive shape
foreach($queryShapes as $shape) {
	$selectShape = $selectShape->union($shape);
}

# Load up the select map.
$selectMap = getMapfile($mapbook, $selectLayer);
$map = ms_newMapObj($CONFIGURATION['root'].$selectMap);

$layer = array_reverse(explode('/', $selectLayer));
$layer = $map->getLayerByName($layer[0]);

$foundShapes = array();

for($i = 0; $i < $map->numlayers; $i++) {
	$layerLoop = $map->getLayer($i);
	$layerLoop->set('status', MS_OFF);	# Turn off extraneous layers
	$layerLoop->set('template', ''); # this should prevent layers from being queried.
}

$queryShapeWkt = $selectShape->toWkt();
# fresh query shape
$q_shape = ms_shapeObjFromWkt($queryShapeWkt);

# Use the map, or layer projection if available.
$projectionQuery = $map->getProjection();
if($layer->getProjection() != NULL) {
	$projectionQuery = $layer->getProjection();
}
	
if($projectionQuery != NULL) {
	# reproject the query shape as available.
	$projectionQuery = ms_newProjectionObj($projectionQuery);
	$q_shape->project($LATLONG_PROJ, $projectionQuery);
}
	
$layer->set('template', $layer->getMetadata('select_record'));
$layer->set('status', MS_DEFAULT);
$layer->open();

$layer->queryByShape($q_shape);
$results = $map->processQueryTemplate(array(), false);

$selectMap = explode("/", substr($selectMap, 2), -1);
$selectMap = implode("/", $selectMap);
$template = implode('', file($CONFIGURATION['root'] . $selectMap . "/" . $layer->getMetadata('select_record')));

for ($i = 0; $i < $layer->getNumResults(); $i++) {
	$shape = $layer->getShape($layer->getResult($i));
	if($q_shape->intersects($shape) == MS_TRUE or $shape->containsShape($q_shape) == MS_TRUE) {
		if($projectionQuery != NULL) {
			$shape->project($projectionQuery, ms_newprojectionobj($projection));
		} else {
			$shape->project($LATLONG_PROJ, ms_newprojectionobj($projection));
		}
		$shape->set("text", $i);
		$foundShapes[] = $shape;
		$results = internalIdTemplate($results, $i, $template);
	}
}

# Array to hold values needed inside output and map file
$dict = array(); 
#Output
$dict['UNIQUEID'] = 'select_'.getmypid().time();
$dict['SHAPEPATH'] = $CONFIGURATION['temp'];
$dict['SHAPE_WKT'] = $shape_wkt;
$dict['MAP_PROJECTION'] = $projection; 
#Templates
$dict['foundShapes'] = sizeof($foundShapes);
$dict['SELECT_LAYER'] = $selectLayer;
$dict['QUERY_LAYER'] = $queryLayer;
$dict['SELECTION_BUFFER'] = $selection_buffer;

if($layer->getMetadata('select_header')) {
	$layer->set('header', $layer->getMetadata('select_header'));
	$headerArray = implode('', file($CONFIGURATION['root'] . $selectMap . "/" . $layer->getMetadata('select_header')));
	$results = processTemplate($headerArray, $dict) . $results;
}
if($layer->getMetadata('select_footer')) {
	$layer->set('footer', $layer->getMetadata('select_footer'));
	$footerArray = implode('', file($CONFIGURATION['root'] . $selectMap . "/" . $layer->getMetadata('select_footer')));
	$dict['footer'] = processTemplate($footerArray, $dict);
}
$selectShape->project($LATLONG_PROJ, ms_newprojectionobj($projection));
$dict['queryShape'] = $selectShape;

$finalShape = $selectShape;
$finalShape->toWkt();
foreach($foundShapes as $shape) {
	$shape->toWkt();
	$finalShape = $finalShape->union($shape);
}
$finalShape->toWkt();
$dict['FINAL_SHAPE_WKT'] = $finalShape->toWkt();
$dict['foundShapesArray'] = $foundShapes;
$dict['results'] = $results;
$dict["fileName"] = basename(__FILE__, '.php');

# Get the type of query to return
switch(strtoupper(urldecode(get_request_icase('type')))) {
	case "WFS":
		outputDatabase($dict, "WFS");
		break;
	case "WMSMEMORY":
		outputMemory($dict);
		break;
	case "HTML":
		outputHTML($dict);
		break;
	case "WMSDATABASE":
	default:
		outputDatabase($dict, "WMS");
		break;
}
?>