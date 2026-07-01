(function () {
	'use strict';

	function ready(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback);
			return;
		}

		callback();
	}

	function decodeColumnar(value) {
		if (!value) {
			return [];
		}

		if (Array.isArray(value)) {
			return value;
		}

		if (!value.fields || !value.data) {
			return [];
		}

		return value.data.map(function (row) {
			var item = {};

			value.fields.forEach(function (field, index) {
				item[field] = row[index];
			});

			return item;
		});
	}

	function getPayload(root) {
		var data = window.ProductJSONViewerData && window.ProductJSONViewerData.json
			? window.ProductJSONViewerData.json
			: null;
		var payload = root.querySelector('[data-product-json-payload]');

		if (data) {
			return data;
		}

		if (!payload || !payload.textContent.trim()) {
			return {};
		}

		try {
			return JSON.parse(payload.textContent);
		} catch (error) {
			return {};
		}
	}

	function setNotice(root, message) {
		root.innerHTML = '';
		root.classList.add('product-json-viewer--empty');
		root.textContent = message;
	}

	function initViewer(root) {
		if (!window.Konva) {
			setNotice(root, 'The product viewer could not load its rendering library.');
			return;
		}

		var data = getPayload(root);
		var pcbCanvas = root.querySelector('#pcb-canvas');
		var schCanvas = root.querySelector('#sch-canvas');
		var pcbZoom = root.querySelector('#pcb-zoom');
		var schZoom = root.querySelector('#sch-zoom');
		var fitPcb = root.querySelector('#fit-pcb');
		var fitSch = root.querySelector('#fit-sch');
		var loading = root.querySelector('#product-json-viewer-loading');
		var tooltip = root.querySelector('#product-json-viewer-tooltip');

		if (!pcbCanvas || !schCanvas) {
			return;
		}

		var pcbStage = null;
		var schStage = null;
		var pcbLayers = {};
		var schLayers = {};
		var pcbTransform = { x: 0, y: 0, scale: 1 };
		var schTransform = { x: 0, y: 0, scale: 1 };
		var pcbBounds = null;
		var schBounds = null;
		var resizeObserver = null;
		var pinRegistry = {};

		var zoomMin = 1;
		var zoomMax = 5;
		var highlightColor = '#00e5ff';
		var pcbBase = '#f5a623';
		var schematicBase = '#ef9a9a';

		var layerColors = {
			'Top Overlay': '#4fc3f7',
			'Mechanical 11': '#78909c',
			'Mechanical 13': '#78909c',
			'Mechanical 15': '#607d8b',
			'Drill Drawing (Top Layer - Bottom Layer)': '#90a4ae'
		};

		function showLoading() {
			if (loading) {
				loading.classList.add('is-active');
			}
		}

		function hideLoading() {
			if (loading) {
				loading.classList.remove('is-active');
			}
		}

		function showTip(text, event) {
			if (!tooltip || !text || !event) {
				return;
			}

			tooltip.textContent = text;
			tooltip.style.left = event.clientX + 14 + 'px';
			tooltip.style.top = event.clientY + 14 + 'px';
			tooltip.style.display = 'block';
		}

		function hideTip() {
			if (tooltip) {
				tooltip.style.display = 'none';
			}
		}

		function moveTip(event) {
			if (!tooltip || tooltip.style.display !== 'block') {
				return;
			}

			tooltip.style.left = event.clientX + 14 + 'px';
			tooltip.style.top = event.clientY + 14 + 'px';
		}

		function clampTransform(transform, bounds, stageWidth, stageHeight) {
			var margin = 40;
			var scale = transform.scale;
			var x1;
			var y1;
			var x2;
			var y2;

			if (!bounds) {
				return;
			}

			x1 = transform.x + bounds.x * scale;
			y1 = transform.y + bounds.y * scale;
			x2 = x1 + bounds.w * scale;
			y2 = y1 + bounds.h * scale;

			if (x2 < margin) {
				transform.x += margin - x2;
			}

			if (x1 > stageWidth - margin) {
				transform.x -= x1 - (stageWidth - margin);
			}

			if (y2 < margin) {
				transform.y += margin - y2;
			}

			if (y1 > stageHeight - margin) {
				transform.y -= y1 - (stageHeight - margin);
			}
		}

		function applyTransform(layers, transform, zoomElement) {
			Object.keys(layers).forEach(function (key) {
				var layer = layers[key];

				layer.x(transform.x);
				layer.y(transform.y);
				layer.scaleX(transform.scale);
				layer.scaleY(transform.scale);
				layer.draw();
			});

			if (zoomElement) {
				zoomElement.textContent = Math.round(transform.scale * 100) + '%';
			}
		}

		function applyPcbTransform() {
			applyTransform(pcbLayers, pcbTransform, pcbZoom);
		}

		function applySchTransform() {
			applyTransform(schLayers, schTransform, schZoom);
		}

		function highlightPin(designator, text, event) {
			var entry = pinRegistry[designator];

			if (!entry) {
				return;
			}

			if (entry.pcb) {
				entry.pcb.stroke(highlightColor);
				entry.pcb.fill(highlightColor + '50');
				entry.pcb.shadowColor(highlightColor);
				entry.pcb.shadowBlur(12);
				entry.pcb.shadowOpacity(0.9);
				pcbLayers.pin.draw();
			}

			if (entry.sch) {
				entry.sch.getChildren().forEach(function (child) {
					if (child.className === 'Rect') {
						child.fill(highlightColor);
						child.shadowColor(highlightColor);
						child.shadowBlur(10);
						child.shadowOpacity(0.9);
					}

					if (child.className === 'Line') {
						child.stroke(highlightColor);
						child.shadowColor(highlightColor);
						child.shadowBlur(8);
						child.shadowOpacity(0.8);
					}

					if (child.className === 'Text') {
						child.fill(highlightColor + 'cc');
					}
				});
				schLayers.pin.draw();
			}

			showTip(text, event);
		}

		function unhighlightPin(designator) {
			var entry = pinRegistry[designator];

			if (!entry) {
				return;
			}

			if (entry.pcb) {
				entry.pcb.stroke(pcbBase);
				entry.pcb.fill(pcbBase + '30');
				entry.pcb.shadowBlur(0);
				entry.pcb.shadowOpacity(0);
				pcbLayers.pin.draw();
			}

			if (entry.sch) {
				entry.sch.getChildren().forEach(function (child) {
					if (child.className === 'Rect') {
						child.fill(schematicBase);
						child.shadowBlur(0);
						child.shadowOpacity(0);
					}

					if (child.className === 'Line') {
						child.stroke(schematicBase);
						child.shadowBlur(0);
						child.shadowOpacity(0);
					}

					if (child.className === 'Text') {
						child.fill(schematicBase + '80');
					}
				});
				schLayers.pin.draw();
			}

			hideTip();
		}

		function getStageSize(element) {
			return {
				w: element.clientWidth || 480,
				h: element.clientHeight || 360
			};
		}

		function setupResize() {
			if (resizeObserver) {
				resizeObserver.disconnect();
			}

			if (!window.ResizeObserver) {
				return;
			}

			resizeObserver = new ResizeObserver(function () {
				var pcbSize;
				var schSize;

				if (pcbStage) {
					pcbSize = getStageSize(pcbCanvas);
					pcbStage.width(pcbSize.w);
					pcbStage.height(pcbSize.h);
					applyPcbTransform();
				}

				if (schStage) {
					schSize = getStageSize(schCanvas);
					schStage.width(schSize.w);
					schStage.height(schSize.h);
					applySchTransform();
				}
			});

			resizeObserver.observe(pcbCanvas);
			resizeObserver.observe(schCanvas);
		}

		function setupPcbInteraction() {
			var panStart = null;

			pcbStage.on('wheel', function (event) {
				var pointer = pcbStage.getPointerPosition();
				var oldScale = pcbTransform.scale;
				var modelPointer;
				var direction;
				var nextScale;

				event.evt.preventDefault();

				if (!pointer) {
					return;
				}

				modelPointer = {
					x: (pointer.x - pcbTransform.x) / oldScale,
					y: (pointer.y - pcbTransform.y) / oldScale
				};
				direction = event.evt.deltaY > 0 ? -1 : 1;
				nextScale = Math.max(zoomMin, Math.min(zoomMax, oldScale * (1 + direction * 0.12)));

				pcbTransform.scale = nextScale;
				pcbTransform.x = pointer.x - modelPointer.x * nextScale;
				pcbTransform.y = pointer.y - modelPointer.y * nextScale;
				clampTransform(pcbTransform, pcbBounds, pcbStage.width(), pcbStage.height());
				applyPcbTransform();
			});

			pcbStage.on('mousedown touchstart', function () {
				var pointer = pcbStage.getPointerPosition();

				if (!pointer) {
					return;
				}

				panStart = {
					mx: pointer.x,
					my: pointer.y,
					tx: pcbTransform.x,
					ty: pcbTransform.y
				};
				pcbStage.container().style.cursor = 'grabbing';
			});

			pcbStage.on('mousemove touchmove', function () {
				var pointer = pcbStage.getPointerPosition();

				if (!panStart || !pointer) {
					return;
				}

				pcbTransform.x = panStart.tx + (pointer.x - panStart.mx);
				pcbTransform.y = panStart.ty + (pointer.y - panStart.my);
				clampTransform(pcbTransform, pcbBounds, pcbStage.width(), pcbStage.height());
				applyPcbTransform();
			});

			pcbStage.on('mouseup touchend', function () {
				panStart = null;
				pcbStage.container().style.cursor = 'default';
			});
		}

		function setupSchInteraction() {
			var panStart = null;

			schStage.on('wheel', function (event) {
				var pointer = schStage.getPointerPosition();
				var oldScale = schTransform.scale;
				var modelPointer;
				var direction;
				var nextScale;

				event.evt.preventDefault();

				if (!pointer) {
					return;
				}

				modelPointer = {
					x: (pointer.x - schTransform.x) / oldScale,
					y: (pointer.y - schTransform.y) / oldScale
				};
				direction = event.evt.deltaY > 0 ? -1 : 1;
				nextScale = Math.max(zoomMin, Math.min(zoomMax, oldScale * (1 + direction * 0.12)));

				schTransform.scale = nextScale;
				schTransform.x = pointer.x - modelPointer.x * nextScale;
				schTransform.y = pointer.y - modelPointer.y * nextScale;
				clampTransform(schTransform, schBounds, schStage.width(), schStage.height());
				applySchTransform();
			});

			schStage.on('mousedown touchstart', function () {
				var pointer = schStage.getPointerPosition();

				if (!pointer) {
					return;
				}

				panStart = {
					mx: pointer.x,
					my: pointer.y,
					tx: schTransform.x,
					ty: schTransform.y
				};
				schStage.container().style.cursor = 'grabbing';
			});

			schStage.on('mousemove touchmove', function () {
				var pointer = schStage.getPointerPosition();

				if (!panStart || !pointer) {
					return;
				}

				schTransform.x = panStart.tx + (pointer.x - panStart.mx);
				schTransform.y = panStart.ty + (pointer.y - panStart.my);
				clampTransform(schTransform, schBounds, schStage.width(), schStage.height());
				applySchTransform();
			});

			schStage.on('mouseup touchend', function () {
				panStart = null;
				schStage.container().style.cursor = 'default';
			});
		}

		function initStages() {
			var pcbSize = getStageSize(pcbCanvas);
			var schSize = getStageSize(schCanvas);

			if (pcbStage) {
				pcbStage.destroy();
			}

			if (schStage) {
				schStage.destroy();
			}

			pcbStage = new Konva.Stage({
				container: pcbCanvas,
				width: pcbSize.w,
				height: pcbSize.h
			});
			schStage = new Konva.Stage({
				container: schCanvas,
				width: schSize.w,
				height: schSize.h
			});

			pcbLayers = {
				board: new Konva.Layer(),
				track: new Konva.Layer(),
				pad: new Konva.Layer(),
				hole: new Konva.Layer(),
				via: new Konva.Layer(),
				pin: new Konva.Layer(),
				comp: new Konva.Layer()
			};
			schLayers = {
				line: new Konva.Layer(),
				poly: new Konva.Layer(),
				arc: new Konva.Layer(),
				comp: new Konva.Layer(),
				pin: new Konva.Layer(),
				label: new Konva.Layer()
			};

			Object.keys(pcbLayers).forEach(function (key) {
				pcbStage.add(pcbLayers[key]);
			});
			Object.keys(schLayers).forEach(function (key) {
				schStage.add(schLayers[key]);
			});

			setupPcbInteraction();
			setupSchInteraction();
			setupResize();
		}

		function renderPCB(pcb) {
			var pads = decodeColumnar(pcb.pds);
			var tracks = decodeColumnar(pcb.tr);
			var pins = decodeColumnar(pcb.pn);
			var components = decodeColumnar(pcb.cmps);
			var holes = decodeColumnar(pcb.hs);
			var vias = decodeColumnar(pcb.vs);
			var minX = Infinity;
			var minY = Infinity;
			var maxX = -Infinity;
			var maxY = -Infinity;
			var margin = 24;
			var canvasWidth;
			var canvasHeight;
			var baseScale;
			var drawnWidth;
			var drawnHeight;
			var offsetX;
			var offsetY;
			var padByPin;

			Object.keys(pcbLayers).forEach(function (key) {
				pcbLayers[key].destroyChildren();
			});
			pcbTransform = { x: 0, y: 0, scale: 1 };

			pads.forEach(function (pad) {
				minX = Math.min(minX, Number(pad.x) || 0);
				minY = Math.min(minY, Number(pad.y) || 0);
				maxX = Math.max(maxX, Number(pad.x) || 0);
				maxY = Math.max(maxY, Number(pad.y) || 0);
			});
			tracks.forEach(function (track) {
				minX = Math.min(minX, Number(track.x1) || 0, Number(track.x2) || 0);
				minY = Math.min(minY, Number(track.y1) || 0, Number(track.y2) || 0);
				maxX = Math.max(maxX, Number(track.x1) || 0, Number(track.x2) || 0);
				maxY = Math.max(maxY, Number(track.y1) || 0, Number(track.y2) || 0);
			});

			if (!isFinite(minX)) {
				minX = 0;
				minY = 0;
				maxX = 100;
				maxY = 100;
			}

			canvasWidth = pcbStage.width();
			canvasHeight = pcbStage.height();
			baseScale = Math.min(
				(canvasWidth - margin * 2) / (maxX - minX || 1),
				(canvasHeight - margin * 2) / (maxY - minY || 1)
			);
			drawnWidth = (maxX - minX) * baseScale;
			drawnHeight = (maxY - minY) * baseScale;
			offsetX = (canvasWidth - drawnWidth) / 2;
			offsetY = (canvasHeight - drawnHeight) / 2;

			function tx(x) {
				return ((Number(x) || 0) - minX) * baseScale + offsetX;
			}

			function ty(y) {
				return (maxY - (Number(y) || 0)) * baseScale + offsetY;
			}

			pcbBounds = {
				x: offsetX,
				y: offsetY,
				w: drawnWidth,
				h: drawnHeight
			};

			tracks.forEach(function (track) {
				pcbLayers.track.add(new Konva.Line({
					points: [tx(track.x1), ty(track.y1), tx(track.x2), ty(track.y2)],
					stroke: layerColors[track.la] || '#4fc3f7',
					strokeWidth: Math.max(0.5, (Number(track.wd) || 0.5) * baseScale),
					lineCap: 'round',
					listening: false
				}));
			});

			holes.forEach(function (hole) {
				pcbLayers.hole.add(new Konva.Circle({
					x: tx(hole.x),
					y: ty(hole.y),
					radius: Math.max(1, (Number(hole.id) || 0.5) * baseScale * 0.5),
					fill: '#1a1a2e',
					stroke: '#546e7a',
					strokeWidth: 0.5,
					listening: false
				}));
			});

			vias.forEach(function (via) {
				pcbLayers.via.add(new Konva.Circle({
					x: tx(via.x),
					y: ty(via.y),
					radius: Math.max(1.5, (Number(via.od) || 1) * baseScale * 0.4),
					fill: '#263238',
					stroke: '#80cbc4',
					strokeWidth: 0.5,
					listening: false
				}));
			});

			pads.forEach(function (pad) {
				var width = Math.max(2, (Number(pad.w) || 1) * baseScale * 0.5);
				var height = Math.max(2, (Number(pad.h) || 1) * baseScale * 0.5);

				pcbLayers.pad.add(new Konva.Rect({
					x: tx(pad.x) - width / 2,
					y: ty(pad.y) - height / 2,
					width: width,
					height: height,
					fill: '#80cbc430',
					stroke: '#80cbc4',
					strokeWidth: 0.3,
					listening: false
				}));
			});

			components.forEach(function (component) {
				var cx = tx(component.x);
				var cy = ty(component.y);
				var group = new Konva.Group();
				var label = component.nm || '';

				group.add(new Konva.Line({
					points: [cx - 7, cy, cx + 7, cy],
					stroke: '#a5d6a7',
					strokeWidth: 1,
					opacity: 0.9
				}));
				group.add(new Konva.Line({
					points: [cx, cy - 7, cx, cy + 7],
					stroke: '#a5d6a7',
					strokeWidth: 1,
					opacity: 0.9
				}));
				group.add(new Konva.Circle({
					x: cx,
					y: cy,
					radius: 3.5,
					stroke: '#a5d6a7',
					strokeWidth: 1,
					fill: 'transparent'
				}));
				group.add(new Konva.Text({
					x: cx - 15,
					y: cy - 14,
					text: label,
					fontSize: 6,
					fontFamily: 'Share Tech Mono, monospace',
					fill: '#a5d6a7',
					opacity: 0.9
				}));
				group.on('mouseenter', function (event) {
					showTip(label + '\n(' + Number(component.x).toFixed(2) + ', ' + Number(component.y).toFixed(2) + ')\n' + (component.la || ''), event.evt);
				});
				group.on('mouseleave', hideTip);
				pcbLayers.comp.add(group);
			});

			padByPin = new Map();
			pads.forEach(function (pad) {
				padByPin.set(pad.pn, pad);
			});
			pins.forEach(function (pin) {
				var matchedPad = padByPin.get(pin.pn);
				var width;
				var height;
				var designator;
				var node;

				if (!matchedPad) {
					return;
				}

				width = (Number(pin.w) || 1) * baseScale;
				height = (Number(pin.h) || 1) * baseScale;
				designator = String(pin.pn);
				node = new Konva.Rect({
					x: tx(matchedPad.x) - width / 2,
					y: ty(matchedPad.y) - height / 2,
					width: width,
					height: height,
					fill: pcbBase + '30',
					stroke: pcbBase,
					strokeWidth: 0.5
				});

				if (!pinRegistry[designator]) {
					pinRegistry[designator] = { pcb: null, sch: null };
				}

				pinRegistry[designator].pcb = node;
				node.on('mouseenter', function (event) {
					highlightPin(designator, '[PCB] Pin: ' + designator, event.evt);
				});
				node.on('mouseleave', function () {
					unhighlightPin(designator);
				});
				pcbLayers.pin.add(node);
			});

			Object.keys(pcbLayers).forEach(function (key) {
				pcbLayers[key].draw();
			});
		}

		function renderSchematic(schematic) {
			var components = decodeColumnar(schematic.cmps);
			var pins = decodeColumnar(schematic.pn);
			var lines = decodeColumnar(schematic.ln);
			var rects = decodeColumnar(schematic.rcts);
			var arcs = decodeColumnar(schematic.ar);
			var labels = decodeColumnar(schematic.lb);
			var minX = Infinity;
			var minY = Infinity;
			var maxX = -Infinity;
			var maxY = -Infinity;
			var margin = 24;
			var canvasWidth;
			var canvasHeight;
			var baseScale;
			var drawnWidth;
			var drawnHeight;
			var offsetX;
			var offsetY;

			Object.keys(schLayers).forEach(function (key) {
				schLayers[key].destroyChildren();
			});
			schTransform = { x: 0, y: 0, scale: 1 };

			function collect(x, y) {
				minX = Math.min(minX, Number(x) || 0);
				minY = Math.min(minY, Number(y) || 0);
				maxX = Math.max(maxX, Number(x) || 0);
				maxY = Math.max(maxY, Number(y) || 0);
			}

			components.forEach(function (component) {
				collect(component.x, component.y);
			});
			pins.forEach(function (pin) {
				collect(pin.x, pin.y);
			});
			rects.forEach(function (rect) {
				collect(rect.x1, rect.y1);
				collect(rect.x2, rect.y2);
			});

			if (!isFinite(minX)) {
				minX = 0;
				minY = 0;
				maxX = 1000;
				maxY = 800;
			}

			canvasWidth = schStage.width();
			canvasHeight = schStage.height();
			baseScale = Math.min(
				(canvasWidth - margin * 2) / (maxX - minX || 1),
				(canvasHeight - margin * 2) / (maxY - minY || 1)
			);
			drawnWidth = (maxX - minX) * baseScale;
			drawnHeight = (maxY - minY) * baseScale;
			offsetX = (canvasWidth - drawnWidth) / 2;
			offsetY = (canvasHeight - drawnHeight) / 2;

			function tx(x) {
				return ((Number(x) || 0) - minX) * baseScale + offsetX;
			}

			function ty(y) {
				return canvasHeight - offsetY - ((Number(y) || 0) - minY) * baseScale;
			}

			schBounds = {
				x: offsetX,
				y: offsetY,
				w: drawnWidth,
				h: drawnHeight
			};

			rects.forEach(function (rect) {
				var x = tx(Math.min(Number(rect.x1) || 0, Number(rect.x2) || 0));
				var y = ty(Math.max(Number(rect.y1) || 0, Number(rect.y2) || 0));
				var width = Math.abs(tx(rect.x2) - tx(rect.x1));
				var height = Math.abs(ty(rect.y2) - ty(rect.y1));

				schLayers.comp.add(new Konva.Rect({
					x: x,
					y: y,
					width: width,
					height: height,
					stroke: '#4fc3f7',
					strokeWidth: Math.max(0.5, (Number(rect.lw) || 0.5) * baseScale * 0.1),
					fill: rect.is ? '#4fc3f710' : '#4fc3f705',
					cornerRadius: 1
				}));
			});

			lines.forEach(function (line) {
				schLayers.line.add(new Konva.Line({
					points: [tx(line.x1), ty(line.y1), tx(line.x2), ty(line.y2)],
					stroke: '#4fc3f780',
					strokeWidth: Math.max(0.3, (Number(line.lw) || 0.5) * baseScale * 0.1),
					listening: false
				}));
			});

			arcs.forEach(function (arc) {
				schLayers.arc.add(new Konva.Arc({
					x: tx(arc.cx),
					y: ty(arc.cy),
					innerRadius: 0,
					outerRadius: (Number(arc.r) || 0) * baseScale,
					angle: Number(arc.sw) || 360,
					rotation: -(Number(arc.sa) || 0),
					stroke: '#4fc3f780',
					strokeWidth: Math.max(0.3, (Number(arc.lw) || 0.5) * baseScale * 0.08),
					fill: 'transparent',
					listening: false
				}));
			});

			pins.forEach(function (pin) {
				var px = tx(pin.x);
				var py = ty(pin.y);
				var length = Math.max(6, (Number(pin.ln) || 20) * baseScale * 0.15);
				var direction = ((Number(pin.cg) || 0) >> 1) & 1;
				var dx = direction ? -length : length;
				var designator = String(pin.dsg || '');
				var group = new Konva.Group();

				group.add(new Konva.Line({
					points: [px, py, px + dx, py],
					stroke: schematicBase,
					strokeWidth: 1,
					opacity: 0.8
				}));
				group.add(new Konva.Rect({
					x: px - 2,
					y: py - 2,
					width: 4,
					height: 4,
					fill: schematicBase,
					opacity: 0.9
				}));

				if (pin.nm) {
					group.add(new Konva.Text({
						x: px - 28,
						y: py - 5,
						width: 52,
						align: direction ? 'right' : 'left',
						text: pin.nm,
						fontSize: 7,
						fontFamily: 'Share Tech Mono, monospace',
						fill: schematicBase + '80'
					}));
				}

				if (designator) {
					if (!pinRegistry[designator]) {
						pinRegistry[designator] = { pcb: null, sch: null };
					}

					pinRegistry[designator].sch = group;
				}

				group.on('mouseenter', function (event) {
					highlightPin(designator, '[SCH] Pin: ' + (pin.nm || '?') + ' (' + designator + ')', event.evt);
				});
				group.on('mouseleave', function () {
					unhighlightPin(designator);
				});
				schLayers.pin.add(group);
			});

			labels.forEach(function (label) {
				schLayers.label.add(new Konva.Text({
					x: tx(label.x),
					y: ty(label.y),
					text: label.tx || label.t || '',
					fontSize: Math.max(6, (Number(label.fh) || 10) * baseScale * 0.1),
					fontFamily: 'Share Tech Mono, monospace',
					fill: '#b0bec5cc',
					listening: false
				}));
			});

			Object.keys(schLayers).forEach(function (key) {
				schLayers[key].draw();
			});
		}

		function render() {
			showLoading();

			Object.keys(pinRegistry).forEach(function (key) {
				delete pinRegistry[key];
			});
			initStages();

			if (data.pcb) {
				renderPCB(data.pcb);
			}

			if (data.schematic) {
				renderSchematic(data.schematic);
			}

			applyPcbTransform();
			applySchTransform();
			hideLoading();
		}

		document.addEventListener('mousemove', moveTip);

		if (fitPcb) {
			fitPcb.addEventListener('click', function () {
				pcbTransform = { x: 0, y: 0, scale: 1 };
				applyPcbTransform();
			});
		}

		if (fitSch) {
			fitSch.addEventListener('click', function () {
				schTransform = { x: 0, y: 0, scale: 1 };
				applySchTransform();
			});
		}

		if (!data || (!data.pcb && !data.schematic)) {
			setNotice(root, 'The product JSON file does not contain PCB or schematic data.');
			return;
		}

		render();
	}

	ready(function () {
		document.querySelectorAll('[data-product-json-viewer]').forEach(initViewer);
	});
})();
