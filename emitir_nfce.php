<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('Location: login.php');
    exit;
}
require_once 'conexao.php';

// Validação de sessão
if (empty($_SESSION['nome_usuario_logado']) || empty($_SESSION['cnpj_loja_logada'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}
date_default_timezone_set('America/Sao_Paulo');

// Validar ID da venda
if (!isset($_GET['id_venda'])) {
    header("Location: caixa.php?msg=erro_nfce");
    exit;
}
$id_venda = intval($_GET['id_venda']);
$cnpj_loja = preg_replace('/[^0-9]/', '', $_SESSION['cnpj_loja_logada']);

// Obter dados da NFC-e
$stmt = $pdo->prepare("SELECT n.*, l.nome_fantasia, l.razao_social, l.endereco, l.cidade, l.uf, l.cep, l.telefone, l.email, l.inscricao_estadual 
                      FROM nfce n
                      JOIN lojas l ON n.id_loja = l.id_loja
                      WHERE n.id_venda = ? AND l.cnpj = ?");
$stmt->execute([$id_venda, $cnpj_loja]);
$nfce = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$nfce) {
    header("Location: caixa.php?msg=nfce_nao_encontrada");
    exit;
}

// Obter itens da NFC-e
$stmt = $pdo->prepare("SELECT * FROM nfce_itens WHERE id_nfce = ?");
$stmt->execute([$nfce['id_nfce']]);
$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obter pagamentos da venda
$stmt = $pdo->prepare("SELECT forma_pagamento, valor_pago FROM pagamentos_venda WHERE id_venda = ?");
$stmt->execute([$id_venda]);
$pagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obter vendedor
$stmt = $pdo->prepare("SELECT usuario_vendedor FROM vendas WHERE id_venda = ?");
$stmt->execute([$id_venda]);
$venda = $stmt->fetch(PDO::FETCH_ASSOC);

// Gerar chave de acesso (44 dígitos)
$chave_acesso = str_pad(substr($cnpj_loja, 0, 14), 14, '0', STR_PAD_LEFT) . 
                date('ymd') . 
                str_pad($nfce['id_nfce'], 8, '0', STR_PAD_LEFT) . 
                '59' . // Modelo NFC-e
                '001' . // Série
                mt_rand(10000000, 99999999);

// URL do QR Code (SP como exemplo)
$url_qrcode = "https://www.fazenda.sp.gov.br/nfce/consulta?p=" . $chave_acesso . "|2|1|1|" . number_format($nfce['valor_total'], 2, '', '');

// Formatar data
$data_emissao = date('d/m/Y H:i:s', strtotime($nfce['data_emissao'] ?? $nfce['data_solicitacao'] ?? 'now'));

// Calcular totais
$total_produtos = array_sum(array_column($itens, 'valor_total'));
$total_pago = array_sum(array_column($pagamentos, 'valor_pago'));
$troco = max(0, $total_pago - $nfce['valor_total']);

// Funções auxiliares
function formatarCNPJ($cnpj) {
    return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $cnpj);
}
function formatarTelefone($telefone) {
    $tel = preg_replace('/[^0-9]/', '', $telefone);
    return strlen($tel) === 11 ? 
        preg_replace("/(\d{2})(\d{5})(\d{4})/", "(\$1) \$2-\$3", $tel) :
        preg_replace("/(\d{2})(\d{4})(\d{4})/", "(\$1) \$2-\$3", $tel);
}
function formatarFormaPagamento($forma) {
    $formas = [
        'DINHEIRO' => 'Dinheiro',
        'CARTAO_CREDITO' => 'Cartão Crédito',
        'CARTAO_DEBITO' => 'Cartão Débito',
        'PIX' => 'Pix'
    ];
    return $formas[$forma] ?? $forma;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>NFC-e #<?= str_pad($nfce['id_nfce'], 8, '0', STR_PAD_LEFT) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Estilos para tela */
        @media screen {
            body {
                background: #f0f0f0;
                padding: 20px;
                font-family: Arial, sans-serif;
            }
            .back-btn {
                display: block;
                width: 200px;
                margin: 0 auto 15px auto;
                padding: 10px;
                background: #2c3e50;
                color: white;
                border: none;
                border-radius: 4px;
                font-size: 16px;
                cursor: pointer;
                text-align: center;
                text-decoration: none;
            }
            .nfce-container {
                max-width: 320px;
                margin: 0 auto;
                background: white;
                border-radius: 0;
                box-shadow: none;
                border: 2px solid #000;
                position: relative;
                padding: 2px;
            }
            .print-btn {
                display: block;
                width: 200px;
                margin: 15px auto;
                padding: 10px;
                background: #27ae60;
                color: white;
                border: none;
                border-radius: 4px;
                font-size: 16px;
                cursor: pointer;
                text-align: center;
            }
        }
        
        /* Estilos para impressão */
        @media print {
            @page {
                size: 80mm auto;
                margin: 0;
            }
            body {
                padding: 0;
                margin: 0;
                background: white;
                font-size: 12px;
            }
            .nfce-container {
                width: 100%;
                max-width: 100%;
                box-shadow: none;
                border-radius: 0;
                border: 1px solid #000 !important;
            }
            .print-btn, .back-btn {
                display: none !important;
            }
        }
        
        /* Estilos compartilhados */
        .header {
            padding: 10px;
            text-align: center;
            border-bottom: 1px dashed #000;
        }
        .header h1 {
            font-size: 18px;
            margin: 0 0 5px 0;
            text-transform: uppercase;
        }
        .header p {
            font-size: 12px;
            margin: 0;
        }
        .section {
            padding: 8px 10px;
            border-bottom: 1px dashed #ccc;
        }
        .section-title {
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 13px;
            text-align: center;
            text-transform: uppercase;
        }
        .loja-info p, .nfce-info p {
            margin: 3px 0;
            font-size: 12px;
            text-align: center;
        }
        .item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 12px;
        }
        .item-name {
            flex: 2;
        }
        .item-value {
            flex: 1;
            text-align: right;
            font-weight: bold;
        }
        .item-details {
            font-size: 11px;
            color: #555;
            margin-left: 10px;
        }
        .totals {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
            margin: 10px 0;
            font-size: 13px;
        }
        .payment {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 12px;
        }
        .payment-type {
            font-weight: bold;
        }
        .qrcode-container {
            text-align: center;
            padding: 10px;
        }
        .qrcode-img {
            width: 130px;
            height: 130px;
            margin: 5px auto;
            border: 1px solid #ddd;
        }
        .chave-acesso {
            font-size: 10px;
            word-break: break-all;
            text-align: center;
            margin-top: 5px;
            font-family: monospace;
        }
        .footer {
            text-align: center;
            padding: 10px;
            font-size: 10px;
            color: #666;
        }
        .vendedor {
            text-align: center;
            font-size: 12px;
            margin: 5px 0;
            font-weight: bold;
        }
    </style>
    <script>
        window.onload = function() {
            // Imprime automaticamente após 1 segundo
            setTimeout(function() {
                window.print();
            }, 1000);
        };
    </script>
</head>
<body>
    <a href="caixa.php" class="back-btn">← Voltar ao Caixa</a>
    <button class="print-btn" onclick="window.print()">🖨️ Imprimir NFC-e</button>
    
    <div class="nfce-container">
        <div class="header">
            <h1><?= htmlspecialchars($nfce['nome_fantasia']) ?></h1>
            <p>Documento Auxiliar da NFC-e</p>
            <p>Não permite aproveitamento de crédito fiscal</p>
        </div>
        
        <div class="section">
            <div class="section-title">Dados do emitente</div>
            <div class="loja-info">
                <p><strong><?= htmlspecialchars($nfce['razao_social']) ?></strong></p>
                <p>CNPJ: <?= formatarCNPJ($cnpj_loja) ?></p>
                <p><?= htmlspecialchars($nfce['endereco']) ?></p>
                <p><?= htmlspecialchars($nfce['cidade']) ?>/<?= htmlspecialchars($nfce['uf']) ?></p>
                <?php if ($nfce['telefone']): ?>
                <p>Tel: <?= formatarTelefone($nfce['telefone']) ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">Dados da nfc-e</div>
            <div class="nfce-info">
                <p><strong>Número:</strong> <?= str_pad($nfce['id_nfce'], 8, '0', STR_PAD_LEFT) ?></p>
                <p><strong>Emissão:</strong> <?= $data_emissao ?></p>
                <p><strong>Protocolo:</strong> <?= substr($chave_acesso, 0, 15) ?>...</p>
            </div>
            <div class="vendedor">Vendedor: <?= htmlspecialchars($venda['usuario_vendedor']) ?></div>
        </div>
        
        <div class="section">
            <div class="section-title">Itens</div>
            <?php foreach ($itens as $item): ?>
            <div class="item">
                <div class="item-name">
                    <?= htmlspecialchars($item['nome_produto']) ?>
                    <div class="item-details">
                        <?= number_format($item['quantidade'], 3, ',', '.') ?> UN × R$ <?= number_format($item['valor_unitario'], 2, ',', '.') ?>
                    </div>
                </div>
                <div class="item-value">R$ <?= number_format($item['valor_total'], 2, ',', '.') ?></div>
            </div>
            <?php endforeach; ?>
            
            <div class="totals">
                <span>TOTAL</span>
                <span>R$ <?= number_format($nfce['valor_total'], 2, ',', '.') ?></span>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">Pagamento</div>
            <?php foreach ($pagamentos as $pagamento): ?>
            <div class="payment">
                <span class="payment-type"><?= formatarFormaPagamento($pagamento['forma_pagamento']) ?>:</span>
                <span>R$ <?= number_format($pagamento['valor_pago'], 2, ',', '.') ?></span>
            </div>
            <?php endforeach; ?>
            
            <?php if ($troco > 0): ?>
            <div class="payment">
                <span class="payment-type">Troco:</span>
                <span>R$ <?= number_format($troco, 2, ',', '.') ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <div class="section-title">Consumidor</div>
            <p style="text-align:center;">CONSUMIDOR NÃO IDENTIFICADO</p>
        </div>
        
        <div class="qrcode-container">
            <p>Consulte via QR Code</p>
            <img class="qrcode-img" src="https://api.qrserver.com/v1/create-qr-code/?size=130x130&data=<?= urlencode($url_qrcode) ?>" alt="QR Code">
            <div class="chave-acesso"><?= chunk_split($chave_acesso, 4, ' ') ?></div>
        </div>
        
        <div class="footer">
            <p>NFC-e emitida em ambiente de homologação</p>
            <p>Consulte em: www.fazenda.sp.gov.br/nfce/consulta</p>
        </div>
    </div>
</body>
</html>