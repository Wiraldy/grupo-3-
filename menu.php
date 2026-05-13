<?php
// ===== INICIO DEL BLOQUE PHP - Procesamiento del pedido =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    
    $host     = 'localhost';
    $dbname   = 'inventario_lc';
    $username = 'root';
    $password = '';

    try {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'No se recibieron datos']);
        exit();
    }

    $nombre     = isset($input['nombre']) ? trim($input['nombre']) : 'Cliente Visitante';
    $telefono   = isset($input['telefono']) ? trim($input['telefono']) : '';
    $direccion  = isset($input['direccion']) ? trim($input['direccion']) : '';
    $referencia = isset($input['referencia']) ? trim($input['referencia']) : '';
    $productos  = isset($input['productos']) ? trim($input['productos']) : '';
    $total      = isset($input['total']) ? floatval($input['total']) : 0;
    $metodoPago = isset($input['metodo_pago']) ? trim($input['metodo_pago']) : 'efectivo';
    $tarjeta    = isset($input['tarjeta']) ? trim($input['tarjeta']) : '';
    $items      = isset($input['items']) ? $input['items'] : [];

    if (empty($telefono) || empty($direccion)) {
        echo json_encode(['success' => false, 'error' => 'Teléfono y dirección son obligatorios']);
        exit();
    }

    if (empty($productos) || $total <= 0) {
        echo json_encode(['success' => false, 'error' => 'El carrito está vacío']);
        exit();
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO pedidos_clientes (nombre_cliente, telefono, direccion, referencia, metodo_pago, tarjeta_numero, productos, total, estado, tiempo_estimado, fecha)
            VALUES (:nombre, :telefono, :direccion, :referencia, :metodo_pago, :tarjeta, :productos, :total, 'pendiente', :tiempo_estimado, NOW())
        ");

        $cantidadTotal = 0;
        foreach ($items as $item) {
            $cantidadTotal += intval($item['cantidad']);
        }
        $tiempoEstimado = 20 + ($cantidadTotal * 3);

        $stmt->execute([
            ':nombre'          => $nombre,
            ':telefono'        => $telefono,
            ':direccion'       => $direccion,
            ':referencia'      => $referencia,
            ':metodo_pago'     => $metodoPago,
            ':tarjeta'         => $tarjeta,
            ':productos'       => $productos,
            ':total'           => $total,
            ':tiempo_estimado' => $tiempoEstimado,
        ]);

        $pedidoId = $pdo->lastInsertId();
        $alertasStock = [];

        foreach ($items as $item) {
            $nombreProducto = $item['nombre'];
            $cantidadPedido = floatval($item['cantidad']);

            $stmtProd = $pdo->prepare("SELECT id, stock_minimo FROM productos WHERE nombre = :nombre AND activo = 1 LIMIT 1");
            $stmtProd->execute([':nombre' => $nombreProducto]);
            $producto = $stmtProd->fetch();

            if ($producto) {
                $productoId  = $producto['id'];
                $stockMinimo = floatval($producto['stock_minimo']);
                $fechaHoy    = date('Y-m-d');

                $stmtSalida = $pdo->prepare("
                    INSERT INTO salidas (producto_id, cantidad, fecha, tipo, notas, creado_por)
                    VALUES (:producto_id, :cantidad, :fecha, 'venta', :notas, NULL)
                ");
                $stmtSalida->execute([
                    ':producto_id' => $productoId,
                    ':cantidad'    => $cantidadPedido,
                    ':fecha'       => $fechaHoy,
                    ':notas'       => "Pedido #{$pedidoId} - Venta web",
                ]);

                $stmtInv = $pdo->prepare("
                    SELECT id, cantidad FROM inventario_diario 
                    WHERE producto_id = :producto_id AND fecha = :fecha
                ");
                $stmtInv->execute([':producto_id' => $productoId, ':fecha' => $fechaHoy]);
                $inventario = $stmtInv->fetch();

                if ($inventario) {
                    $nuevaCantidad = floatval($inventario['cantidad']) - $cantidadPedido;
                    $stmtUpdateInv = $pdo->prepare("UPDATE inventario_diario SET cantidad = :cantidad WHERE id = :id");
                    $stmtUpdateInv->execute([':cantidad' => $nuevaCantidad, ':id' => $inventario['id']]);

                    if ($nuevaCantidad <= $stockMinimo) {
                        $alertasStock[] = $nombreProducto . ' (' . $nuevaCantidad . ' unidades)';
                        $stmtNotif = $pdo->prepare("
                            INSERT INTO notificaciones (tipo, titulo, mensaje, leida, resuelta, prioridad, fecha, producto_id)
                            VALUES ('bajo_stock', :titulo, :mensaje, 0, 0, 1, NOW(), :producto_id)
                        ");
                        $stmtNotif->execute([
                            ':titulo'      => '⚠️ Bajo Stock: ' . $nombreProducto,
                            ':mensaje'     => $nombreProducto . ' tiene ' . $nuevaCantidad . ' unidades (mín: ' . $stockMinimo . ')',
                            ':producto_id' => $productoId,
                        ]);
                    }
                } else {
                    $stmtInsertInv = $pdo->prepare("
                        INSERT INTO inventario_diario (producto_id, fecha, cantidad, estado, creado_por)
                        VALUES (:producto_id, :fecha, :cantidad, 'bajo', NULL)
                        ON DUPLICATE KEY UPDATE cantidad = cantidad - :cantidad2
                    ");
                    $stmtInsertInv->execute([
                        ':producto_id' => $productoId,
                        ':fecha'       => $fechaHoy,
                        ':cantidad'    => -$cantidadPedido,
                        ':cantidad2'   => $cantidadPedido,
                    ]);
                }
            }
        }

        $pdo->commit();

        $respuesta = [
            'success'         => true,
            'pedido_id'       => $pedidoId,
            'mensaje'         => 'Pedido procesado correctamente',
            'tiempo_estimado' => $tiempoEstimado . ' min',
        ];

        if (!empty($alertasStock)) {
            $respuesta['alertas_stock'] = $alertasStock;
        }

        echo json_encode($respuesta);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Error al procesar: ' . $e->getMessage()]);
    }
    exit();
}
// ===== FIN DEL BLOQUE PHP =====
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Menú — Little Caesars</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .font-bebas { font-family: 'Bebas Neue', cursive; }
        .gradient-text { background: linear-gradient(45deg, #47281d, #f7931e); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .nav-link { position: relative; transition: all 0.3s ease; }
        .nav-link::after { content: ''; position: absolute; bottom: -5px; left: 0; width: 0; height: 3px; background: #ff6b35; transition: width 0.3s ease; }
        .nav-link:hover::after, .nav-link.active::after { width: 100%; }
        .nav-link.active { color: #f97316 !important; }

        .cart-float {
            position: fixed; bottom: 32px; right: 32px; z-index: 997;
            background: linear-gradient(135deg, #ff6b35, #f7931e);
            border: none; border-radius: 50px; padding: 14px 22px;
            color: #fff; font-family: 'Inter', sans-serif; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; gap: 10px;
            box-shadow: 0 8px 30px rgba(255,107,53,0.55), 0 2px 8px rgba(0,0,0,0.12);
            transition: all 0.25s cubic-bezier(0.34,1.56,0.64,1);
        }
        .cart-float:hover { transform: scale(1.07) translateY(-4px); box-shadow: 0 16px 40px rgba(255,107,53,0.65); }
        .cart-float:active { transform: scale(0.97); }
        .cart-float-emoji { font-size: 1.35rem; }
        .cart-float-label { font-size: 0.88rem; letter-spacing: 0.02em; }
        .cart-float-count { background: #1a1a1a; color: #fff; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 0.74rem; font-weight: 800; transition: transform 0.3s cubic-bezier(0.34,1.56,0.64,1); }
        .cart-float-count.bump { transform: scale(1.7); }
        .cart-float::before { content: ''; position: absolute; inset: -4px; border-radius: 50px; border: 3px solid rgba(255,107,53,0.4); opacity: 0; animation: rpulse 2.5s ease-in-out infinite; }
        .cart-float.has-items::before { opacity: 1; }
        @keyframes rpulse { 0%,100%{transform:scale(1);opacity:0.5} 50%{transform:scale(1.07);opacity:0} }

        .cart-overlay { position: fixed; inset: 0; background: rgba(10,10,10,0.55); backdrop-filter: blur(6px); z-index: 998; opacity: 0; pointer-events: none; transition: opacity 0.3s; }
        .cart-overlay.open { opacity: 1; pointer-events: all; }
        .cart-drawer { position: fixed; top: 0; right: -430px; width: 415px; max-width: 95vw; height: 100%; background: #fff; z-index: 999; display: flex; flex-direction: column; box-shadow: -12px 0 50px rgba(0,0,0,0.16); transition: right 0.38s cubic-bezier(0.4,0,0.2,1); }
        .cart-drawer.open { right: 0; }
        .cart-header { padding: 0 22px; height: 70px; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg,#ff6b35,#f7931e); color: #fff; flex-shrink: 0; }
        .cart-header h2 { font-family:'Bebas Neue',cursive; font-size: 1.65rem; letter-spacing:0.05em; display:flex; align-items:center; gap:8px; }
        .cart-close { width:34px; height:34px; border-radius:50%; background:rgba(255,255,255,0.22); border:none; color:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:0.95rem; transition:background 0.2s; }
        .cart-close:hover { background:rgba(255,255,255,0.38); }
        .cart-body { flex:1; overflow-y:auto; padding:14px; }
        .cart-body::-webkit-scrollbar{width:4px} .cart-body::-webkit-scrollbar-thumb{background:#ffd0b0;border-radius:4px}
        .cart-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; gap:10px; }
        .cart-empty .em { font-size:4rem; }
        .cart-empty p { font-weight:600; color:#bbb; font-size:0.95rem; }
        .cart-empty small { color:#ddd; font-size:0.8rem; }
        .cart-item { display:flex; gap:12px; padding:12px 13px; border:1px solid #f5f5f5; border-radius:14px; margin-bottom:10px; background:#fafafa; align-items:flex-start; animation:itemIn 0.28s cubic-bezier(0.34,1.4,0.64,1); }
        @keyframes itemIn{from{opacity:0;transform:translateX(20px) scale(0.95)}to{opacity:1;transform:none}}
        .ci-img { width:60px; height:60px; border-radius:10px; object-fit:cover; flex-shrink:0; }
        .ci-info { flex:1; min-width:0; }
        .ci-name { font-weight:700; font-size:0.87rem; color:#1a1a1a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .ci-price { color:#ff6b35; font-weight:700; font-size:0.82rem; margin-top:3px; }
        .qty-ctrl { display:flex; align-items:center; gap:8px; margin-top:8px; }
        .qty-btn { width:26px; height:26px; border-radius:50%; border:1.5px solid #ff6b35; background:#fff; color:#ff6b35; font-size:1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.15s; line-height:1; }
        .qty-btn:hover { background:#ff6b35; color:#fff; }
        .qty-num { font-weight:800; font-size:0.88rem; min-width:20px; text-align:center; }
        .ci-rm { background:none; border:none; color:#ddd; cursor:pointer; padding:4px; border-radius:6px; transition:color 0.2s; flex-shrink:0; }
        .ci-rm:hover { color:#ef4444; }
        .cart-footer { padding:16px 20px; border-top:1px solid #f0f0f0; flex-shrink:0; }
        .total-row { display:flex; justify-content:space-between; align-items:center; padding:8px 0 12px; border-bottom:1px dashed #f0d0c0; margin-bottom:12px; }
        .total-row span { color:#888; font-size:0.88rem; font-weight:600; }
        .total-row strong { font-family:'Bebas Neue',cursive; font-size:1.6rem; color:#1a1a1a; }
        .btn-checkout { width:100%; background:linear-gradient(135deg,#ff6b35,#f7931e); color:#fff; border:none; border-radius:14px; padding:14px; font-family:'Bebas Neue',cursive; font-size:1.2rem; letter-spacing:0.08em; cursor:pointer; box-shadow:0 4px 16px rgba(255,107,53,0.4); transition:all 0.2s; }
        .btn-checkout:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(255,107,53,0.5); }
        .btn-clear { width:100%; background:none; color:#bbb; border:1.5px solid #ececec; border-radius:10px; padding:8px; font-size:0.79rem; font-weight:600; cursor:pointer; margin-top:8px; transition:all 0.2s; }
        .btn-clear:hover { border-color:#ef4444; color:#ef4444; }

        .modal { display:none; position:fixed; inset:0; z-index:1200; background:rgba(0,0,0,0.65); backdrop-filter:blur(8px); align-items:center; justify-content:center; }
        .modal.open { display:flex; }
        .modal-box { 
            background:#fff; 
            border-radius:24px; 
            width:90%; 
            max-width:540px; 
            max-height:90vh;
            overflow-y: auto;
            box-shadow:0 28px 70px rgba(0,0,0,0.25); 
            animation:mIn 0.3s cubic-bezier(0.34,1.4,0.64,1);
            display: flex;
            flex-direction: column;
        }
        .modal-box::-webkit-scrollbar { width: 5px; }
        .modal-box::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .modal-box::-webkit-scrollbar-thumb { background: #ffb185; border-radius: 10px; }
        
        .alert-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 28px;
            max-width: 340px;
            width: 85%;
            z-index: 1300;
            box-shadow: 0 25px 45px rgba(0,0,0,0.25);
            animation: alertSlide 0.28s cubic-bezier(0.34,1.4,0.64,1);
            overflow: hidden;
        }
        @keyframes alertSlide {
            from { opacity: 0; transform: translate(-50%, -45%) scale(0.92); }
            to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
        }
        .alert-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(3px);
            z-index: 1299;
            transition: opacity 0.2s;
        }
        .alert-content {
            padding: 24px 22px 18px 22px;
            text-align: center;
        }
        .alert-icon { font-size: 2.8rem; margin-bottom: 12px; }
        .alert-title {
            font-weight: 800;
            font-size: 1.3rem;
            font-family: 'Bebas Neue', cursive;
            letter-spacing: 0.03em;
            margin-bottom: 8px;
            color: #1a1a1a;
        }
        .alert-message {
            font-size: 0.88rem;
            color: #4b5563;
            line-height: 1.45;
            margin-bottom: 22px;
        }
        .alert-button {
            background: linear-gradient(135deg, #ff6b35, #f7931e);
            border: none;
            padding: 10px 24px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.85rem;
            color: white;
            cursor: pointer;
            transition: all 0.2s;
            width: auto;
            min-width: 110px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .alert-button:hover {
            transform: scale(0.98);
            background: linear-gradient(135deg, #e55a2b, #e08518);
        }
        
        @keyframes mIn{from{opacity:0;transform:scale(0.88) translateY(18px)}to{opacity:1;transform:none}}

        .cfield { width:100%; border:1.5px solid #e8e8e8; border-radius:12px; padding:11px 14px; font-family:'Inter',sans-serif; font-size:0.9rem; outline:none; transition:all 0.2s; background:#fafafa; }
        .cfield:focus { border-color:#ff6b35; background:#fff; box-shadow:0 0 0 4px rgba(255,107,53,0.08); }
        .clabel { display:block; font-weight:700; font-size:0.77rem; text-transform:uppercase; letter-spacing:0.06em; color:#666; margin-bottom:5px; }

        .filter-btn { transition:all 0.22s; border:2px solid #ff6b35; color:#ff6b35; background:#fff; border-radius:50px; padding:10px 22px; font-weight:700; font-size:0.85rem; cursor:pointer; }
        .filter-btn:hover,.filter-btn.active { background:linear-gradient(135deg,#ff6b35,#f7931e); color:#fff; border-color:transparent; box-shadow:0 4px 12px rgba(255,107,53,0.35); }
        .card-hover { transition:all 0.3s ease; }
        .card-hover:hover { transform:translateY(-8px); box-shadow:0 20px 40px rgba(0,0,0,0.12); }
        
        @media (max-width: 480px) {
            .cart-float { bottom: 18px; right: 18px; padding: 10px 16px; }
            .alert-modal { width: 80%; max-width: 300px; }
        }
    </style>
</head>
<body class="bg-gray-50" style="font-family:'Inter',sans-serif;">

<!-- NAVBAR -->
<nav class="bg-white shadow-lg fixed w-full top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <a href="menu.php" class="flex items-center">
                <img src="logo.jpg" alt="Little Caesars" class="h-10 w-auto">
                <span class="font-bebas text-3xl ml-3 gradient-text">Little Caesars</span>
            </a>
            <div class="hidden md:flex items-baseline space-x-8">
                <a href="index.html" class="nav-link text-gray-900 hover:text-orange-500 px-3 py-2 text-sm font-medium">INICIO</a>
                <a href="menu.php" class="nav-link active text-gray-900 px-3 py-2 text-sm font-medium">MENÚ</a>
                <a href="contacto.html" class="nav-link text-gray-900 hover:text-orange-500 px-3 py-2 text-sm font-medium">CONTACTO</a>
                <a href="sucursales.html" class="nav-link text-gray-900 hover:text-orange-500 px-3 py-2 text-sm font-medium">SUCURSALES</a>
                <a href="acerca-de-nosotros.html" class="nav-link text-gray-900 hover:text-orange-500 px-3 py-2 text-sm font-medium">NOSOTROS</a>
            </div>
            <button id="mb-btn" class="md:hidden text-gray-900"><i class="fas fa-bars text-xl"></i></button>
        </div>
    </div>
    <div id="mb-menu" class="hidden md:hidden bg-white border-t">
        <div class="px-2 pt-2 pb-3 space-y-1">
            <a href="index.html" class="block px-3 py-2 text-gray-900 font-medium">INICIO</a>
            <a href="menu.php" class="block px-3 py-2 text-orange-600 font-bold">MENÚ</a>
            <a href="contacto.html" class="block px-3 py-2 text-gray-900 font-medium">CONTACTO</a>
            <a href="sucursales.html" class="block px-3 py-2 text-gray-900 font-medium">SUCURSALES</a>
            <a href="acerca-de-nosotros.html" class="block px-3 py-2 text-gray-900 font-medium">NOSOTROS</a>
        </div>
    </div>
</nav>

<!-- FLOATING CART -->
<button class="cart-float" id="cart-float-btn" onclick="openCart()">
    <span class="cart-float-emoji">🛒</span>
    <span class="cart-float-label">Mi Pedido</span>
    <span class="cart-float-count" id="cart-count">0</span>
</button>

<!-- OVERLAY + DRAWER -->
<div class="cart-overlay" id="cart-overlay" onclick="closeCart()"></div>
<div class="cart-drawer" id="cart-drawer">
    <div class="cart-header">
        <h2>🛒 Tu Pedido</h2>
        <button class="cart-close" onclick="closeCart()"><i class="fas fa-times"></i></button>
    </div>
    <div class="cart-body" id="cart-body">
        <div class="cart-empty" id="cart-empty">
            <div class="em">🍕</div>
            <p>Tu carrito está vacío</p>
            <small>¡Agrega algo delicioso!</small>
        </div>
        <div id="cart-items"></div>
    </div>
    <div class="cart-footer" id="cart-footer" style="display:none">
        <div class="total-row"><span>Total a pagar</span><strong>$<span id="cart-total">0</span></strong></div>
        <button class="btn-checkout" onclick="openCheckout()"><i class="fas fa-bolt" style="margin-right:8px;"></i> CONFIRMAR PEDIDO</button>
        <button class="btn-clear" onclick="clearCart()">🗑 Vaciar carrito</button>
    </div>
</div>

<!-- PRODUCT MODAL -->
<div class="modal" id="productModal"><div class="modal-box" id="modal-box"></div></div>

<!-- CHECKOUT MODAL -->
<div class="modal" id="checkoutModal">
    <div class="modal-box" style="padding:0; overflow:hidden; display:flex; flex-direction:column;">
        <div style="background:linear-gradient(135deg,#ff6b35,#f7931e);padding:18px 24px;color:#fff;display:flex;justify-content:space-between;align-items:center; flex-shrink:0;">
            <div>
                <h2 class="font-bebas" style="font-size:1.85rem;letter-spacing:0.05em; line-height:1.2;">Datos de entrega</h2>
                <p style="font-size:0.75rem;opacity:0.9;margin-top:2px;">Completa para confirmar tu pedido</p>
            </div>
            <button onclick="closeModal('checkoutModal')" style="background:rgba(255,255,255,0.22);border:none;color:#fff;width:36px;height:36px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1rem; transition: all 0.2s;"><i class="fas fa-times"></i></button>
        </div>
        <div style="flex:1; overflow-y: auto; padding: 0;">
            <div style="padding: 20px 24px 28px 24px;">
                <div style="background:#fff8f5;border-radius:14px;padding:14px 16px;margin-bottom:18px;border:1px solid #ffe0d0;">
                    <p style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:#ff6b35;margin-bottom:8px;">Resumen del pedido</p>
                    <div id="co-items" style="font-size:0.86rem;color:#444; max-height: 150px; overflow-y: auto;"></div>
                    <div style="display:flex;justify-content:space-between;margin-top:10px;padding-top:9px;border-top:1px dashed #ffd0b8;">
                        <span style="font-weight:700;font-size:0.9rem;">Total</span>
                        <span style="font-weight:800;color:#ff6b35;font-size:1.1rem;">$<span id="co-total">0</span></span>
                    </div>
                </div>
                <div style="display:grid;gap:14px;">
                    <div><label class="clabel">Dirección *</label><input id="co-dir" type="text" class="cfield" placeholder="Ej: Calle Duarte #12, Santo Domingo"></div>
                    <div><label class="clabel">Teléfono *</label><input id="co-tel" type="tel" class="cfield" placeholder="(809) 000-0000"></div>
                    <div><label class="clabel">Referencia *</label><input id="co-ref" type="text" class="cfield" placeholder="Ej: Frente al parque, casa azul"></div>
                    <div>
                        <label class="clabel">Método de pago</label>
                        <select id="co-pago" class="cfield" onchange="toggleCard()">
                            <option value="efectivo">💵 Efectivo</option>
                            <option value="transferencia">📲 Transferencia</option>
                            <option value="tarjeta">💳 Tarjeta</option>
                        </select>
                    </div>
                    <div id="cardBox" style="display:none;"><label class="clabel">Número de tarjeta</label><input id="co-card" type="text" class="cfield" placeholder="XXXX XXXX XXXX XXXX" maxlength="19"></div>
                </div>
                <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:12px;padding:12px 15px;margin:18px 0;display:flex;align-items:center;gap:10px;">
                    <i class="fas fa-clock" style="color:#16a34a; font-size: 1.1rem;"></i>
                    <span style="font-size:0.87rem;color:#15803d;">Tiempo estimado: <strong id="eta">—</strong></span>
                </div>
                <button class="btn-checkout" onclick="confirmarPedido()" style="margin-top: 8px;"><i class="fas fa-check-circle" style="margin-right:8px;"></i> CONFIRMAR PEDIDO</button>
            </div>
        </div>
    </div>
</div>

<!-- HERO -->
<section class="pt-24 pb-12" style="background:linear-gradient(135deg,#ff6b35,#f7931e);text-align:center;color:#fff;">
    <div class="max-w-5xl mx-auto px-4">
        <h1 class="font-bebas" style="font-size:clamp(3rem,8vw,6rem);letter-spacing:0.03em;text-shadow:0 4px 20px rgba(0,0,0,0.18);">NUESTRO MENÚ</h1>
        <p style="font-size:1.05rem;opacity:0.92;margin-top:6px;font-weight:300;">Pizzas artesanales y aperitivos hechos con amor 🍕</p>
    </div>
</section>

<!-- FILTER BAR -->
<div style="position:sticky;top:64px;z-index:40;background:#fff;box-shadow:0 2px 12px rgba(0,0,0,0.06);padding:13px 0;">
    <div class="max-w-7xl mx-auto px-4 flex flex-wrap justify-center gap-3">
        <button class="filter-btn active" data-f="all">🍽 Todo</button>
        <button class="filter-btn" data-f="pizzas">🍕 Pizzas</button>
        <button class="filter-btn" data-f="aperitivos">🍟 Aperitivos</button>
    </div>
</div>

<!-- GRID -->
<section class="py-14">
    <div class="max-w-7xl mx-auto px-4">
        <h2 class="font-bebas gradient-text text-center mb-10" style="font-size:clamp(2rem,5vw,3.2rem);">Para toda la familia</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8" id="menu-grid">
            <div class="bg-white rounded-2xl overflow-hidden shadow-lg card-hover menu-item" data-cat="pizzas"><img src="piz.png" alt="La Grande" class="w-full h-48 object-cover"><div class="p-6"><h3 class="font-bebas text-2xl mb-1">La Grande</h3><p class="text-gray-500 text-sm mb-4">La pizza clásica, 12 rebanadas perfectas.</p><div class="flex justify-between items-center"><span class="font-bebas text-3xl" style="color:#ff6b35;">$350</span><button class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded-full text-sm font-semibold transition-colors" onclick="openModal('lagrand')">Ver detalles</button></div></div></div>
            <div class="bg-white rounded-2xl overflow-hidden shadow-lg card-hover menu-item" data-cat="pizzas"><img src="pizz.png" alt="Pepperoni Clásico" class="w-full h-48 object-cover"><div class="p-6"><h3 class="font-bebas text-2xl mb-1">Pepperoni Clásico</h3><p class="text-gray-500 text-sm mb-4">Pepperoni premium, mozzarella y salsa casera.</p><div class="flex justify-between items-center"><span class="font-bebas text-3xl" style="color:#ff6b35;">$300</span><button class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded-full text-sm font-semibold transition-colors" onclick="openModal('pepperoni')">Ver detalles</button></div></div></div>
            <div class="bg-white rounded-2xl overflow-hidden shadow-lg card-hover menu-item" data-cat="pizzas"><img src="pizza.png" alt="La Tropical" class="w-full h-48 object-cover"><div class="p-6"><h3 class="font-bebas text-2xl mb-1">La Tropical</h3><p class="text-gray-500 text-sm mb-4">Jamón de alta calidad y piña jugosa.</p><div class="flex justify-between items-center"><span class="font-bebas text-3xl" style="color:#ff6b35;">$400</span><button class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded-full text-sm font-semibold transition-colors" onclick="openModal('tropical')">Ver detalles</button></div></div></div>
            <div class="bg-white rounded-2xl overflow-hidden shadow-lg card-hover menu-item" data-cat="pizzas"><img src="m2.png" alt="Cuatro Quesos" class="w-full h-48 object-cover"><div class="p-6"><h3 class="font-bebas text-2xl mb-1">Cuatro Quesos</h3><p class="text-gray-500 text-sm mb-4">Mozzarella, gorgonzola, parmesano y provolone.</p><div class="flex justify-between items-center"><span class="font-bebas text-3xl" style="color:#ff6b35;">$550</span><button class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded-full text-sm font-semibold transition-colors" onclick="openModal('cuatroq')">Ver detalles</button></div></div></div>
            <div class="bg-white rounded-2xl overflow-hidden shadow-lg card-hover menu-item" data-cat="pizzas"><img src="m3.png" alt="BIG Pizza" class="w-full h-48 object-cover"><div class="p-6"><h3 class="font-bebas text-2xl mb-1">BIG Pizza</h3><p class="text-gray-500 text-sm mb-4">Mezcla de mozzarella y un toque de parmesano.</p><div class="flex justify-between items-center"><span class="font-bebas text-3xl" style="color:#ff6b35;">$550</span><button class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded-full text-sm font-semibold transition-colors" onclick="openModal('bigp')">Ver detalles</button></div></div></div>
            <div class="bg-white rounded-2xl overflow-hidden shadow-lg card-hover menu-item" data-cat="aperitivos"><img src="ap.png" alt="Yummy Comb" class="w-full h-48 object-cover"><div class="p-6"><h3 class="font-bebas text-2xl mb-1">Yummy Comb</h3><p class="text-gray-500 text-sm mb-4">Combinación de jamón, piña y mozzarella.</p><div class="flex justify-between items-center"><span class="font-bebas text-3xl" style="color:#ff6b35;">$200</span><button class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded-full text-sm font-semibold transition-colors" onclick="openModal('yummy')">Ver detalles</button></div></div></div>
            <div class="bg-white rounded-2xl overflow-hidden shadow-lg card-hover menu-item" data-cat="aperitivos"><img src="ape 1.png" alt="BBpizza" class="w-full h-48 object-cover"><div class="p-6"><h3 class="font-bebas text-2xl mb-1">BBpizza</h3><p class="text-gray-500 text-sm mb-4">Perfecto para entradas y compartir.</p><div class="flex justify-between items-center"><span class="font-bebas text-3xl" style="color:#ff6b35;">$190</span><button class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded-full text-sm font-semibold transition-colors" onclick="openModal('bbp')">Ver detalles</button></div></div></div>
            <div class="bg-white rounded-2xl overflow-hidden shadow-lg card-hover menu-item" data-cat="aperitivos"><img src="ape2.png" alt="Golden Snack" class="w-full h-48 object-cover"><div class="p-6"><h3 class="font-bebas text-2xl mb-1">Golden Snack</h3><p class="text-gray-500 text-sm mb-4">Entrada dorada, crujiente y deliciosa.</p><div class="flex justify-between items-center"><span class="font-bebas text-3xl" style="color:#ff6b35;">$150</span><button class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded-full text-sm font-semibold transition-colors" onclick="openModal('golden')">Ver detalles</button></div></div></div>
            <div class="bg-white rounded-2xl overflow-hidden shadow-lg card-hover menu-item" data-cat="aperitivos"><img src="ape3.png" alt="Tropicality" class="w-full h-48 object-cover"><div class="p-6"><h3 class="font-bebas text-2xl mb-1">Tropicality</h3><p class="text-gray-500 text-sm mb-4">Jamón, piña y mozzarella en armonía.</p><div class="flex justify-between items-center"><span class="font-bebas text-3xl" style="color:#ff6b35;">$199</span><button class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded-full text-sm font-semibold transition-colors" onclick="openModal('tropicality')">Ver detalles</button></div></div></div>
            <div class="bg-white rounded-2xl overflow-hidden shadow-lg card-hover menu-item" data-cat="aperitivos"><img src="ape4.png" alt="DELIZA" class="w-full h-48 object-cover"><div class="p-6"><h3 class="font-bebas text-2xl mb-1">DELIZA</h3><p class="text-gray-500 text-sm mb-4">Mini slice de pizza, irresistible bocado.</p><div class="flex justify-between items-center"><span class="font-bebas text-3xl" style="color:#ff6b35;">$199</span><button class="bg-orange-500 hover:bg-orange-600 text-white px-5 py-2 rounded-full text-sm font-semibold transition-colors" onclick="openModal('deliza')">Ver detalles</button></div></div></div>
        </div>
    </div>
</section>

<footer class="bg-gray-900 text-white py-10">
    <div class="max-w-7xl mx-auto px-4 text-center">
        <div class="flex items-center justify-center mb-4"><img src="logo.jpg" class="h-10 mr-3"><span class="font-bebas text-4xl gradient-text">Little Caesars</span></div>
        <p class="text-gray-400 text-sm">© 2024 Little Caesar Pizzería. Todos los derechos reservados.</p>
    </div>
</footer>

<script>
const P = {
    lagrand:     {name:'La Grande',        img:'piz.png',   price:350, desc:'La pizza clásica con sabor inigualable, 12 rebanadas perfectas para compartir en familia.',     tags:['Salsa de tomate','Mozzarella','Aceite de oliva']},
    pepperoni:   {name:'Pepperoni Clásico',img:'pizz.png',  price:300, desc:'Generosas porciones de pepperoni premium sobre salsa de tomate casera y mozzarella derretida.', tags:['Salsa tomate','Mozzarella','Pepperoni']},
    tropical:    {name:'La Tropical',      img:'pizza.png', price:400, desc:'Jamón de alta calidad y piña jugosa sobre mozzarella perfectamente derretida.',                  tags:['Jamón','Piña','Mozzarella']},
    cuatroq:     {name:'Cuatro Quesos',    img:'m2.png',    price:550, desc:'Sinfonía de mozzarella, gorgonzola, parmesano y provolone sobre masa artesanal.',               tags:['Mozzarella','Gorgonzola','Parmesano','Provolone']},
    bigp:        {name:'BIG Pizza',        img:'m3.png',    price:550, desc:'La pizza grande con mezcla perfecta de mozzarella y parmesano.',                                tags:['Mozzarella','Parmesano']},
    yummy:       {name:'Yummy Comb',       img:'ap.png',    price:200, desc:'Combinación perfecta de jamón, piña y mozzarella en una deliciosa entrada.',                    tags:['Jamón','Piña','Mozzarella']},
    bbp:         {name:'BBpizza',          img:'ape 1.png', price:190, desc:'Perfecta entrada para compartir con los que más quieres.',                                      tags:['Pepperoni','Mozzarella']},
    golden:      {name:'Golden Snack',     img:'ape2.png',  price:150, desc:'Entrada dorada y crujiente, perfecta para comenzar la experiencia.',                            tags:['Queso','Salsa']},
    tropicality: {name:'Tropicality',      img:'ape3.png',  price:199, desc:'Jamón y piña en perfecta armonía tropical.',                                                   tags:['Jamón','Piña','Mozzarella']},
    deliza:      {name:'DELIZA',           img:'ape4.png',  price:199, desc:'Mini slice irresistible, todo el sabor de una pizza en un bocado perfecto.',                   tags:['Pepperoni','Tomate','Mozzarella']},
};

document.getElementById('mb-btn').addEventListener('click',()=>document.getElementById('mb-menu').classList.toggle('hidden'));

document.querySelectorAll('.filter-btn').forEach(b=>{
    b.addEventListener('click',()=>{
        document.querySelectorAll('.filter-btn').forEach(x=>x.classList.remove('active'));
        b.classList.add('active');
        const f=b.dataset.f;
        document.querySelectorAll('.menu-item').forEach(i=>i.style.display=(f==='all'||i.dataset.cat===f)?'':'none');
    });
});

let cart={};
function openCart(){document.getElementById('cart-drawer').classList.add('open');document.getElementById('cart-overlay').classList.add('open');}
function closeCart(){document.getElementById('cart-drawer').classList.remove('open');document.getElementById('cart-overlay').classList.remove('open');}

function addToCart(id){
    if(cart[id]) cart[id].qty++; else cart[id]={...P[id],id,qty:1};
    renderCart(); bump(); closeModal('productModal'); openCart();
}
function changeQty(id,d){if(!cart[id])return;cart[id].qty+=d;if(cart[id].qty<=0)delete cart[id];renderCart();}
function removeItem(id){delete cart[id];renderCart();}
function clearCart(){cart={};renderCart();}

function renderCart(){
    const items=Object.values(cart);
    const qty=items.reduce((s,i)=>s+i.qty,0);
    const total=items.reduce((s,i)=>s+i.price*i.qty,0);
    document.getElementById('cart-count').textContent=qty;
    const fb=document.getElementById('cart-float-btn');
    qty>0?fb.classList.add('has-items'):fb.classList.remove('has-items');
    document.getElementById('cart-empty').style.display=items.length?'none':'flex';
    document.getElementById('cart-footer').style.display=items.length?'':'none';
    document.getElementById('cart-total').textContent=total;
    const el=document.getElementById('cart-items'); el.innerHTML='';
    items.forEach(item=>{
        const div=document.createElement('div'); div.className='cart-item';
        div.innerHTML=`<img src="${item.img}" class="ci-img" onerror="this.style.display='none'"><div class="ci-info"><div class="ci-name">${item.name}</div><div class="ci-price">$${item.price} c/u · $${item.price*item.qty} total</div><div class="qty-ctrl"><button class="qty-btn" onclick="changeQty('${item.id}',-1)">−</button><span class="qty-num">${item.qty}</span><button class="qty-btn" onclick="changeQty('${item.id}',1)">+</button></div></div><button class="ci-rm" onclick="removeItem('${item.id}')"><i class="fas fa-times"></i></button>`;
        el.appendChild(div);
    });
}
function bump(){const e=document.getElementById('cart-count');e.classList.remove('bump');void e.offsetWidth;e.classList.add('bump');setTimeout(()=>e.classList.remove('bump'),400);}

function openModal(id){
    const p=P[id]; if(!p)return;
    document.getElementById('modal-box').innerHTML=`<img src="${p.img}" style="width:100%;height:230px;object-fit:cover;border-radius:24px 24px 0 0;"><div style="padding:22px 24px 26px;"><div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;"><h2 class="font-bebas" style="font-size:1.95rem;color:#1a1a1a;">${p.name}</h2><span class="font-bebas" style="font-size:1.9rem;color:#ff6b35;">$${p.price}</span></div><p style="color:#777;font-size:0.87rem;line-height:1.65;margin-bottom:14px;">${p.desc}</p><div style="margin-bottom:18px;"><p style="font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;color:#bbb;margin-bottom:7px;">Ingredientes</p><div style="display:flex;flex-wrap:wrap;gap:6px;">${p.tags.map(t=>`<span style="background:#fff8f5;border:1px solid #ffe0d0;color:#ff6b35;border-radius:20px;padding:4px 11px;font-size:0.77rem;font-weight:700;">${t}</span>`).join('')}</div></div><button onclick="addToCart('${id}')" style="width:100%;background:linear-gradient(135deg,#ff6b35,#f7931e);color:#fff;border:none;border-radius:14px;padding:14px;font-family:'Bebas Neue',cursive;font-size:1.2rem;letter-spacing:0.08em;cursor:pointer;box-shadow:0 4px 16px rgba(255,107,53,0.4);">🛒 AGREGAR AL CARRITO</button></div>`;
    document.getElementById('productModal').classList.add('open');
}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.getElementById('productModal').addEventListener('click',e=>{if(e.target===document.getElementById('productModal'))closeModal('productModal');});
document.getElementById('checkoutModal').addEventListener('click',e=>{if(e.target===document.getElementById('checkoutModal'))closeModal('checkoutModal');});

function showCustomAlert(title, message, icon = '🍕') {
    const existingOverlay = document.querySelector('.alert-overlay');
    if(existingOverlay) existingOverlay.remove();
    const existingModal = document.querySelector('.alert-modal');
    if(existingModal) existingModal.remove();
    
    const overlay = document.createElement('div');
    overlay.className = 'alert-overlay';
    document.body.appendChild(overlay);
    
    const modalDiv = document.createElement('div');
    modalDiv.className = 'alert-modal';
    modalDiv.innerHTML = `
        <div class="alert-content">
            <div class="alert-icon">${icon}</div>
            <div class="alert-title">${title}</div>
            <div class="alert-message">${message}</div>
            <button class="alert-button" id="customAlertBtn">ACEPTAR</button>
        </div>
    `;
    document.body.appendChild(modalDiv);
    
    const closeAlert = () => {
        modalDiv.remove();
        overlay.remove();
    };
    document.getElementById('customAlertBtn').addEventListener('click', closeAlert);
    overlay.addEventListener('click', closeAlert);
}

function openCheckout(){
    const items=Object.values(cart); if(!items.length)return;
    const total=items.reduce((s,i)=>s+i.price*i.qty,0);
    const qty=items.reduce((s,i)=>s+i.qty,0);
    document.getElementById('co-items').innerHTML=items.map(i=>`<div style="display:flex;justify-content:space-between;padding:2px 0;"><span>${i.qty}× ${i.name}</span><span style="color:#ff6b35;font-weight:600;">$${i.price*i.qty}</span></div>`).join('');
    document.getElementById('co-total').textContent=total;
    document.getElementById('eta').textContent=(20+qty*3)+' min';
    document.getElementById('checkoutModal').classList.add('open');
    closeCart();
}
function toggleCard(){document.getElementById('cardBox').style.display=document.getElementById('co-pago').value==='tarjeta'?'':'none';}
function confirmarPedido() {
    const direccion  = document.getElementById('co-dir').value.trim();
    const telefono   = document.getElementById('co-tel').value.trim();
    const referencia = document.getElementById('co-ref').value.trim();
    const nombre     = 'Cliente Visitante';

    if (!direccion || !telefono) {
        showCustomAlert('Campos requeridos', 'Por favor completa la dirección y el teléfono antes de continuar.', '⚠️');
        return;
    }

    const items = Object.values(cart);
    if (items.length === 0) {
        showCustomAlert('Carrito vacío', 'Agrega productos antes de confirmar tu pedido.', '🛒');
        return;
    }

    const total           = items.reduce((s, i) => s + i.price * i.qty, 0);
    const productos_texto = items.map(i => `${i.qty}x ${i.name}`).join(', ');

    const boton        = event.currentTarget;
    const textoOriginal = boton.innerHTML;
    boton.innerHTML    = '<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i> Procesando...';
    boton.disabled     = true;

    // Enviar al MISMO archivo (menu.php)
    fetch('menu.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            nombre:     nombre,
            telefono:   telefono,
            direccion:  direccion,
            referencia: referencia,
            productos:  productos_texto,
            total:      total,
            items: items.map(i => ({
                nombre:   i.name,
                cantidad: i.qty,
                precio:   i.price
            }))
        })
    })
    .then(r => r.json())
    .then(result => {
        boton.innerHTML = textoOriginal;
        boton.disabled  = false;

        if (result.success) {
            let extra = '';
            if (result.alertas_stock && result.alertas_stock.length > 0) {
                extra = '\n\n⚠️ Stock bajo en: ' + result.alertas_stock.join(', ');
            }

            showCustomAlert(
                '¡Pedido Confirmado! 🎉',
                `Pedido #${result.pedido_id} recibido.\n\n📦 ${direccion}\n📞 ${telefono}\n💵 Total: $${total}\n\nTiempo estimado: ${document.getElementById('eta').textContent}${extra}`,
                '✅'
            );

            cart = {};
            renderCart();
            closeModal('checkoutModal');
            document.getElementById('co-dir').value = '';
            document.getElementById('co-tel').value = '';
            document.getElementById('co-ref').value = '';
        } else {
            showCustomAlert('Error al procesar', result.error || 'Ocurrió un error inesperado.', '❌');
        }
    })
    .catch(err => {
        boton.innerHTML = textoOriginal;
        boton.disabled  = false;
        showCustomAlert(
            'Error de conexión',
            'No se pudo conectar con el servidor.\n\n' + err.message,
            '🔌'
        );
    });
}
</script>
</body>
</html>