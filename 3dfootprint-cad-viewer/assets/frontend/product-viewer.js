(function () {
	'use strict';

	const ZOOM_MIN = 1;
	const ZOOM_MAX = 5;
	const HL_COLOR = '#00e5ff';
	const HL_SHADOW = '#00e5ff';
	const PCB_BASE = '#ff0000';
	const SCH_BASE = '#ff0000';

	function onReady(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback);
			return;
		}

		callback();
	}

	function scoped(root, selector) {
		return root.querySelector(selector);
	}

	function getViewerJson(root) {
		const payload = scoped(root, '[data-product-json-viewer-payload]');

		if (payload && payload.textContent.trim()) {
			try {
				return JSON.parse(payload.textContent);
			} catch (error) {
				window.console.error('Product JSON viewer payload could not be parsed.', error);
			}
		}

		if (
			window.ProductJSONViewerData &&
			String(window.ProductJSONViewerData.productId) === String(root.dataset.productId)
		) {
			return window.ProductJSONViewerData.json;
		}

		return null;
	}

	function claimThemeImageSection(root) {
		const imageSection = root.closest('.product-section-top-left');

		if (!imageSection) {
			return;
		}

		imageSection.classList.add('product-section-top-left--json-viewer');

		if (root.parentElement !== imageSection) {
			imageSection.appendChild(root);
		}

		Array.from(imageSection.children).forEach(function (child) {
			if (child !== root) {
				child.remove();
			}
		});
	}

	function initViewer(root) {
		const data = getViewerJson(root);

		if (!data || typeof data !== 'object') {
			return;
		}

		claimThemeImageSection(root);

		if (typeof window.Konva === 'undefined') {
			window.console.error('Product JSON viewer requires Konva.');
			return;
		}

		const pcbBox = scoped(root, '[data-product-json-viewer-pcb-box]');
		const schBox = scoped(root, '[data-product-json-viewer-sch-box]');
		const pcbCanvas = scoped(root, '[data-product-json-viewer-pcb-canvas]');
		const schCanvas = scoped(root, '[data-product-json-viewer-sch-canvas]');
		const pcbZoom = scoped(root, '[data-product-json-viewer-pcb-zoom]');
		const schZoom = scoped(root, '[data-product-json-viewer-sch-zoom]');
		const loading = scoped(root, '[data-product-json-viewer-loading]');
		const tip = scoped(root, '[data-product-json-viewer-tooltip]');

		if (!pcbCanvas || !schCanvas) {
			return;
		}

		scoped(pcbBox, '.product-json-viewer__canvas-wrap').style.background = '#000000';
		scoped(schBox, '.product-json-viewer__canvas-wrap').style.background = '#ffffff';

		let pcbStage = null;
		let schStage = null;
		let resizeObserver = null;
		let pcbLayers = {};
		let schLayers = {};
		let pcbTransform = { x: 0, y: 0, scale: 1 };
		let schTransform = { x: 0, y: 0, scale: 1 };
		let pcbBounds = null;
		let schBounds = null;
		const pinRegistry = {};

		function showTip(text, event) {
			if (!tip || !event) {
				return;
			}

			tip.textContent = text;
			tip.style.left = event.clientX + 14 + 'px';
			tip.style.top = event.clientY + 14 + 'px';
			tip.style.display = 'block';
		}

		function hideTip() {
			if (tip) {
				tip.style.display = 'none';
			}
		}

		document.addEventListener('mousemove', function (event) {
			if (tip && tip.style.display === 'block') {
				tip.style.left = event.clientX + 14 + 'px';
				tip.style.top = event.clientY + 14 + 'px';
			}
		});

		function drawNodeLayer(node) {
			const layer = node && node.getLayer ? node.getLayer() : null;

			if (layer) {
				layer.batchDraw();
			}
		}

		function highlightPin(dsg, tooltipText, event) {
			const entry = pinRegistry[dsg];

			if (!entry) {
				return;
			}

			if (entry.pcb) {
				entry.pcb.stroke(HL_COLOR);
				entry.pcb.fill(HL_COLOR + '50');
				entry.pcb.shadowColor(HL_SHADOW);
				entry.pcb.shadowBlur(12);
				entry.pcb.shadowOpacity(0.9);
				drawNodeLayer(entry.pcb);
			}

			if (entry.sch) {
				entry.sch.getChildren().forEach(function (child) {
					if (child.className === 'Rect') {
						child.fill(HL_COLOR);
						child.shadowColor(HL_SHADOW);
						child.shadowBlur(10);
						child.shadowOpacity(0.9);
					}
					if (child.className === 'Line') {
						child.stroke(HL_COLOR);
						child.shadowColor(HL_SHADOW);
						child.shadowBlur(8);
						child.shadowOpacity(0.8);
					}
					if (child.className === 'Text') {
						child.fill(HL_COLOR + 'cc');
					}
				});
				drawNodeLayer(entry.sch);
			}

			if (tooltipText && event) {
				showTip(tooltipText, event);
			}
		}

		function unhighlightPin(dsg) {
			const entry = pinRegistry[dsg];

			if (!entry) {
				return;
			}

			if (entry.pcb) {
				entry.pcb.fill(PCB_BASE);
				entry.pcb.stroke(PCB_BASE);
				entry.pcb.shadowBlur(0);
				entry.pcb.shadowOpacity(0);
				drawNodeLayer(entry.pcb);
			}

			if (entry.sch) {
				entry.sch.getChildren().forEach(function (child) {
					if (child.className === 'Rect') {
						child.fill(SCH_BASE);
						child.shadowBlur(0);
						child.shadowOpacity(0);
					}
					if (child.className === 'Line') {
						child.stroke(SCH_BASE);
						child.shadowBlur(0);
						child.shadowOpacity(0);
					}
					if (child.className === 'Text') {
						child.fill('black');
					}
				});
				drawNodeLayer(entry.sch);
			}

			hideTip();
		}

		function clampTransform(transform, bounds, stageW, stageH) {
			if (!bounds) {
				return;
			}

			const margin = 40;
			const scale = transform.scale;
			const cx1 = transform.x + bounds.x * scale;
			const cy1 = transform.y + bounds.y * scale;
			const cx2 = cx1 + bounds.w * scale;
			const cy2 = cy1 + bounds.h * scale;

			if (cx2 < margin) {
				transform.x += margin - cx2;
			}
			if (cx1 > stageW - margin) {
				transform.x -= cx1 - (stageW - margin);
			}
			if (cy2 < margin) {
				transform.y += margin - cy2;
			}
			if (cy1 > stageH - margin) {
				transform.y -= cy1 - (stageH - margin);
			}
		}

		function applyTransform(layers, transform, zoomEl) {
			Object.values(layers).forEach(function (layer) {
				layer.x(transform.x);
				layer.y(transform.y);
				layer.scaleX(transform.scale);
				layer.scaleY(transform.scale);
				layer.batchDraw();
			});

			if (zoomEl) {
				zoomEl.textContent = Math.round(transform.scale * 100) + '%';
			}
		}

		function applyPcbTransform() {
			applyTransform(pcbLayers, pcbTransform, pcbZoom);
		}

		function applySchTransform() {
			applyTransform(schLayers, schTransform, schZoom);
		}

		function getStageSizePx(container) {
			return {
				w: container.clientWidth || 480,
				h: container.clientHeight || 384,
			};
		}

		function resizeStages() {
			if (pcbStage) {
				const pcbSize = getStageSizePx(pcbCanvas);
				pcbStage.width(pcbSize.w);
				pcbStage.height(pcbSize.h);
			}
			if (schStage) {
				const schSize = getStageSizePx(schCanvas);
				schStage.width(schSize.w);
				schStage.height(schSize.h);
			}
		}

		function setupResize() {
			if (resizeObserver) {
				resizeObserver.disconnect();
			}

			if (typeof ResizeObserver === 'undefined') {
				window.addEventListener('resize', resizeStages);
				return;
			}

			resizeObserver = new ResizeObserver(resizeStages);

			resizeObserver.observe(pcbCanvas);
			resizeObserver.observe(schCanvas);
		}

		function setupStageInteraction(stage, transform, boundsGetter, applyCallback) {
			let panStart = null;

			stage.on('wheel', function (event) {
				event.evt.preventDefault();

				const pointer = stage.getPointerPosition();

				if (!pointer) {
					return;
				}

				const oldScale = transform.scale;
				const mousePointTo = {
					x: (pointer.x - transform.x) / oldScale,
					y: (pointer.y - transform.y) / oldScale,
				};
				const direction = event.evt.deltaY > 0 ? -1 : 1;
				const nextScale = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, oldScale * (1 + direction * 0.12)));

				transform.scale = nextScale;
				transform.x = pointer.x - mousePointTo.x * nextScale;
				transform.y = pointer.y - mousePointTo.y * nextScale;
				clampTransform(transform, boundsGetter(), stage.width(), stage.height());
				applyCallback();
			});

			stage.on('mousedown', function () {
				const pointer = stage.getPointerPosition();

				if (!pointer) {
					return;
				}

				panStart = {
					mx: pointer.x,
					my: pointer.y,
					tx: transform.x,
					ty: transform.y,
				};
				stage.container().style.cursor = 'grabbing';
			});

			stage.on('mousemove', function () {
				if (!panStart) {
					return;
				}

				const pointer = stage.getPointerPosition();

				if (!pointer) {
					return;
				}

				transform.x = panStart.tx + (pointer.x - panStart.mx);
				transform.y = panStart.ty + (pointer.y - panStart.my);
				clampTransform(transform, boundsGetter(), stage.width(), stage.height());
				applyCallback();
			});

			stage.on('mouseup', function () {
				panStart = null;
				stage.container().style.cursor = 'default';
			});

			stage.container().addEventListener('mouseleave', function () {
				panStart = null;
				stage.container().style.cursor = 'default';
			});
		}

		function initStages() {
			if (pcbStage) {
				pcbStage.destroy();
			}
			if (schStage) {
				schStage.destroy();
			}

			const pcbSize = getStageSizePx(pcbCanvas);
			const schSize = getStageSizePx(schCanvas);

			pcbStage = new Konva.Stage({
				container: pcbCanvas,
				width: pcbSize.w,
				height: pcbSize.h,
			});
			schStage = new Konva.Stage({
				container: schCanvas,
				width: schSize.w,
				height: schSize.h,
			});

			pcbLayers = {
				board: new Konva.Layer(),
				track: new Konva.Layer(),
				pad: new Konva.Layer(),
				hole: new Konva.Layer(),
				via: new Konva.Layer(),
				pin: new Konva.Layer(),
				comp: new Konva.Layer(),
			};
			schLayers = {
				line: new Konva.Layer(),
				poly: new Konva.Layer(),
				arc: new Konva.Layer(),
				comp: new Konva.Layer(),
				pin: new Konva.Layer(),
				label: new Konva.Layer(),
			};

			Object.values(pcbLayers).forEach(function (layer) {
				pcbStage.add(layer);
			});
			Object.values(schLayers).forEach(function (layer) {
				schStage.add(layer);
			});

			setupStageInteraction(pcbStage, pcbTransform, function () {
				return pcbBounds;
			}, applyPcbTransform);
			setupStageInteraction(schStage, schTransform, function () {
				return schBounds;
			}, applySchTransform);
			setupResize();
		}

		function renderPCB(PCB) {
			Object.values(pcbLayers).forEach(function (layer) {
				layer.destroyChildren();
			});
			pcbTransform = { x: 0, y: 0, scale: 1 };

			const pads = PCB.pads || [];
			const tracks = PCB.tracks || [];
			const comps = PCB.components || [];
			const holes = PCB.holes || [];
			const vias = PCB.vias || [];

			let minX = Infinity;
			let minY = Infinity;
			let maxX = -Infinity;
			let maxY = -Infinity;

			pads.forEach(function (pad) {
				minX = Math.min(minX, pad.x);
				minY = Math.min(minY, pad.y);
				maxX = Math.max(maxX, pad.x);
				maxY = Math.max(maxY, pad.y);
			});
			tracks.forEach(function (track) {
				minX = Math.min(minX, track.x1, track.x2);
				maxX = Math.max(maxX, track.x1, track.x2);
				minY = Math.min(minY, track.y1, track.y2);
				maxY = Math.max(maxY, track.y1, track.y2);
			});

			if (!isFinite(minX)) {
				minX = 0;
				maxX = 100;
				minY = 0;
				maxY = 100;
			}

			const margin = 24;
			const canvasW = pcbStage.width();
			const canvasH = pcbStage.height();
			const baseScale = Math.min(
				(canvasW - margin * 2) / (maxX - minX || 1),
				(canvasH - margin * 2) / (maxY - minY || 1)
			);
			const drawnW = (maxX - minX) * baseScale;
			const drawnH = (maxY - minY) * baseScale;
			const offX = (canvasW - drawnW) / 2;
			const offY = (canvasH - drawnH) / 2;
			const centerX = (minX + maxX) / 2;
			const tx = function (x) {
				return (x - minX) * baseScale + offX;
			};
			const ty = function (y) {
				return (maxY - y) * baseScale + offY;
			};

			pcbBounds = {
				x: offX,
				y: offY,
				w: drawnW,
				h: drawnH,
			};

			tracks.forEach(function (track) {
				pcbLayers.track.add(
					new Konva.Line({
						points: [tx(track.x1), ty(track.y1), tx(track.x2), ty(track.y2)],
						stroke: 'Yellow',
						strokeWidth: Math.max(0.5, (track.width || 0.5) * baseScale),
						lineCap: 'round',
						listening: false,
					})
				);
			});

			holes.forEach(function (hole) {
				pcbLayers.hole.add(
					new Konva.Circle({
						x: tx(hole.x),
						y: ty(hole.y),
						radius: Math.max(1, (hole.d || 0.5) * baseScale * 0.5),
						fill: '#1a1a2e',
						stroke: '#546e7a',
						strokeWidth: 0.5,
						listening: false,
					})
				);
			});

			vias.forEach(function (via) {
				pcbLayers.via.add(
					new Konva.Circle({
						x: tx(via.x),
						y: ty(via.y),
						radius: Math.max(1.5, (via.d || 1) * baseScale * 0.4),
						fill: '#263238',
						stroke: '#80cbc4',
						strokeWidth: 0.5,
						listening: false,
					})
				);
			});

			pads.forEach(function (pad) {
				const dsg = String(pad.pin);
				const offset = 2.85;
				const padW = Math.max(2, (pad.w || 1) * baseScale * 0.6);
				const padH = Math.max(2, (pad.h || 1) * baseScale);
				const node = new Konva.Rect({
					x: tx(pad.x) - padW / 2 + (pad.x < centerX ? offset : -offset),
					y: ty(pad.y) - padH / 2,
					width: padW,
					height: padH,
					fill: PCB_BASE,
					stroke: PCB_BASE,
					strokeWidth: 0.3,
				});

				if (!pinRegistry[dsg]) {
					pinRegistry[dsg] = { pcb: null, sch: null };
				}

				pinRegistry[dsg].pcb = node;
				node.on('mouseenter', function (event) {
					highlightPin(dsg, '[PCB] Pin: ' + dsg, event.evt);
				});
				node.on('mouseleave', function () {
					unhighlightPin(dsg);
				});
				pcbLayers.pad.add(node);
			});

			comps.forEach(function (comp) {
				const cx = tx(comp.x);
				const cy = ty(comp.y);
				const group = new Konva.Group();

				group.add(new Konva.Line({ points: [cx - 7, cy, cx + 7, cy], stroke: '#a5d6a7', strokeWidth: 1, opacity: 0.9 }));
				group.add(new Konva.Line({ points: [cx, cy - 7, cx, cy + 7], stroke: '#a5d6a7', strokeWidth: 1, opacity: 0.9 }));
				group.add(new Konva.Circle({ x: cx, y: cy, radius: 3.5, stroke: '#a5d6a7', strokeWidth: 1, fill: 'transparent' }));
				group.add(
					new Konva.Text({
						x: cx - 15,
						y: cy - 14,
						text: comp.name,
						fontSize: 6,
						fontFamily: 'Share Tech Mono',
						fill: '#a5d6a7',
						opacity: 0.9,
					})
				);
				group.on('mouseenter', function (event) {
					showTip(
						comp.name + '\n(' + Number(comp.x).toFixed(2) + ', ' + Number(comp.y).toFixed(2) + ')\n' + comp.layer,
						event.evt
					);
				});
				group.on('mouseleave', hideTip);
				pcbLayers.comp.add(group);
			});

			Object.values(pcbLayers).forEach(function (layer) {
				layer.draw();
			});
		}

		function renderSchematic(schematic) {
			Object.values(schLayers).forEach(function (layer) {
				layer.destroyChildren();
			});
			schTransform = { x: 0, y: 0, scale: 1 };

			const components = schematic.components || [];
			const pins = schematic.pins || [];
			const lines = schematic.lines || [];
			const rects = schematic.rects || [];
			const arcs = schematic.arcs || [];
			const labels = schematic.labels || [];
			const allPts = [];

			let minX = Infinity;
			let minY = Infinity;
			let maxX = -Infinity;
			let maxY = -Infinity;

			components.forEach(function (component) {
				allPts.push({ x: component.x, y: component.y });
			});
			pins.forEach(function (pin) {
				allPts.push({ x: pin.x, y: pin.y });
			});
			rects.forEach(function (rect) {
				allPts.push({ x: rect.x1, y: rect.y1 });
				allPts.push({ x: rect.x2, y: rect.y2 });
			});
			allPts.forEach(function (point) {
				minX = Math.min(minX, point.x);
				minY = Math.min(minY, point.y);
				maxX = Math.max(maxX, point.x);
				maxY = Math.max(maxY, point.y);
			});

			if (!isFinite(minX)) {
				minX = 0;
				maxX = 1000;
				minY = 0;
				maxY = 800;
			}

			const margin = 24;
			const width = schStage.width();
			const height = schStage.height();
			const baseScale = Math.min(
				(width - margin * 2) / (maxX - minX || 1),
				(height - margin * 2) / (maxY - minY || 1)
			);
			const drawnW = (maxX - minX) * baseScale;
			const drawnH = (maxY - minY) * baseScale;
			const offX = (width - drawnW) / 2;
			const offY = (height - drawnH) / 2;
			const tx = function (x) {
				return (x - minX) * baseScale + offX;
			};
			const ty = function (y) {
				return height - offY - (y - minY) * baseScale;
			};

			schBounds = {
				x: offX,
				y: offY,
				w: drawnW,
				h: drawnH,
			};

			rects.forEach(function (rect) {
				const x = tx(Math.min(rect.x1, rect.x2));
				const y = ty(Math.max(rect.y1, rect.y2));
				const rectW = Math.abs(tx(rect.x2) - tx(rect.x1));
				const rectH = Math.abs(ty(rect.y2) - ty(rect.y1));

				schLayers.comp.add(
					new Konva.Rect({
						x: x,
						y: y,
						width: rectW,
						height: rectH,
						stroke: 'black',
						fill: 'lightyellow',
						strokeWidth: Math.max(0.5, (rect.lineWidth || 0.5) * baseScale),
						cornerRadius: 2,
					})
				);
			});

			lines.forEach(function (line) {
				const points = (line.points || []).flatMap(function (point) {
					return [tx(point.x), ty(point.y)];
				});

				if (points.length < 4) {
					return;
				}

				schLayers.line.add(
					new Konva.Line({
						points: points,
						stroke: '#4fc3f7',
						strokeWidth: Math.max(0.3, (line.lineWidth || 0.5) * baseScale * 0.1),
						listening: false,
					})
				);
			});

			arcs.forEach(function (arc) {
				const startAngle = arc.startAngle || 0;
				const endAngle = arc.endAngle != null ? arc.endAngle : 360;
				let sweep = endAngle - startAngle;

				if (sweep <= 0) {
					sweep += 360;
				}

				schLayers.arc.add(
					new Konva.Arc({
						x: tx(arc.cx),
						y: ty(arc.cy),
						innerRadius: 0,
						outerRadius: arc.radius * baseScale,
						angle: sweep,
						rotation: -startAngle,
						stroke: '#4fc3f7',
						strokeWidth: Math.max(0.3, (arc.lineWidth || 0.5) * baseScale * 0.08),
						fill: 'transparent',
						listening: false,
					})
				);
			});

			pins.forEach(function (pin) {
				const px = tx(pin.x);
				const py = ty(pin.y);
				const len = Math.max(6, (pin.length || 20) * baseScale * 0.15);
				const dir = ((pin.conglomerate || 0) >> 1) & 1;
				const dx = dir ? -len : len;
				const dsg = String(pin.designator || '');
				const group = new Konva.Group();

				group.add(new Konva.Line({ points: [px, py, px + dx, py], stroke: SCH_BASE, strokeWidth: 1, opacity: 0.8 }));
				group.add(new Konva.Rect({ x: px - 2, y: py - 2, width: 4, height: 4, fill: SCH_BASE, opacity: 0.9 }));

				if (pin.name) {
					group.add(
						new Konva.Text({
							x: px - 28,
							y: py - 5,
							width: 52,
							align: dir ? 'right' : 'left',
							text: pin.name,
							fontSize: 7,
							fontFamily: 'Share Tech Mono,monospace',
							fill: 'black',
						})
					);
				}

				if (dsg) {
					if (!pinRegistry[dsg]) {
						pinRegistry[dsg] = { pcb: null, sch: null };
					}
					pinRegistry[dsg].sch = group;
				}

				group.on('mouseenter', function (event) {
					highlightPin(dsg, '[SCH] Pin: ' + (pin.name || '?') + ' (' + dsg + ')', event.evt);
				});
				group.on('mouseleave', function () {
					unhighlightPin(dsg);
				});
				schLayers.pin.add(group);
			});

			labels.forEach(function (label) {
				schLayers.label.add(
					new Konva.Text({
						x: tx(label.x),
						y: ty(label.y),
						text: label.text || '',
						fontSize: 8,
						fontFamily: 'Share Tech Mono,monospace',
						fill: 'black',
						listening: false,
					})
				);
			});

			Object.values(schLayers).forEach(function (layer) {
				layer.draw();
			});
		}

		function loadData(viewerData) {
			if (loading) {
				loading.classList.add('is-active');
			}

			window.requestAnimationFrame(function () {
				try {
					Object.keys(pinRegistry).forEach(function (key) {
						delete pinRegistry[key];
					});

					initStages();

					if (viewerData.pcb) {
						renderPCB(viewerData.pcb);
					}
					if (viewerData.schematic) {
						renderSchematic(viewerData.schematic);
					}

					applyPcbTransform();
					applySchTransform();
				} catch (error) {
					window.console.error('Product JSON viewer failed to render.', error);
				}

				if (loading) {
					loading.classList.remove('is-active');
				}
			});
		}

		const fitPcb = scoped(root, '[data-product-json-viewer-fit-pcb]');
		const fitSch = scoped(root, '[data-product-json-viewer-fit-sch]');

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

		loadData(data);
	}

	onReady(function () {
		document.querySelectorAll('[data-product-json-viewer]').forEach(initViewer);
	});
})();
