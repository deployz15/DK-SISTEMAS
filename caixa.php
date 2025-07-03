<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('Location: login.php');
    exit;
}

require_once 'conexao.php';



// Validação adicional de sessão
if (empty($_SESSION['nome_usuario_logado']) || empty($_SESSION['cnpj_loja_logada'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Inicializar venda_id como null
$venda_id = null;

// Forçar fuso horário brasileiro
date_default_timezone_set('America/Sao_Paulo');

// Dados do usuário e loja com validação
$usuario = trim($_SESSION['nome_usuario_logado']);
$cnpj_loja = preg_replace('/[^0-9]/', '', $_SESSION['cnpj_loja_logada']);

if (strlen($cnpj_loja) != 14) {
    die("CNPJ inválido na sessão");
}

// Obter informações da loja com prepared statement
$stmt = $pdo->prepare("SELECT id_loja, nome_fantasia FROM lojas WHERE cnpj = :cnpj");
$stmt->bindParam(':cnpj', $cnpj_loja, PDO::PARAM_STR);
$stmt->execute();
$loja = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loja) {
    die("Erro: Loja não encontrada.");
}

$id_loja = $loja['id_loja'];
$nome_loja = $loja['nome_fantasia'];

// Verificar status do caixa com prepared statement
$stmt = $pdo->prepare("SELECT * FROM caixa WHERE cnpj_loja = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$cnpj_loja]);
$caixa = $stmt->fetch(PDO::FETCH_ASSOC);

$caixa_aberto = ($caixa && $caixa['status'] == 'aberto');

// Adicionar novo vendedor com validação
if (isset($_POST['adicionar_vendedor']) && $caixa_aberto) {
    $novo_vendedor = trim($_POST['novo_vendedor'] ?? '');
    
    if (empty($novo_vendedor)) {
        header("Location: caixa.php?msg=nome_vazio");
        exit;
    }
    
    if (strlen($novo_vendedor) < 3) {
        header("Location: caixa.php?msg=nome_curto");
        exit;
    }
    
    // Verificar se vendedor já existe
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario = ? AND cnpj_loja = ?");
    $stmt->execute([$novo_vendedor, $cnpj_loja]);
    $existe = $stmt->fetchColumn();
    
    if ($existe) {
        header("Location: caixa.php?msg=vendedor_existente");
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, cnpj_loja, tipo) VALUES (?, ?, 'vendedor')");
        $stmt->execute([$novo_vendedor, $cnpj_loja]);
        
        $pdo->commit();
        header("Location: caixa.php?msg=vendedor_adicionado");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: caixa.php?msg=erro_adicionar");
        exit;
    }
}

// Remover vendedor com validação
if (isset($_GET['remover_vendedor'])) {
    $vendedor_remover = trim($_GET['remover_vendedor']);
    
    if ($vendedor_remover == $usuario) {
        header("Location: caixa.php?msg=nao_remover_usuario");
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE usuario = ? AND cnpj_loja = ?");
        $stmt->execute([$vendedor_remover, $cnpj_loja]);
        
        $pdo->commit();
        header("Location: caixa.php?msg=vendedor_removido");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: caixa.php?msg=erro_remover");
        exit;
    }
}

// Buscar vendedores ativos
$stmt = $pdo->prepare("SELECT usuario FROM usuarios WHERE cnpj_loja = ? ORDER BY usuario");
$stmt->execute([$cnpj_loja]);
$vendedores = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Processar troca de produto
if ($venda_id && isset($_POST['processar_troca'])) {
    try {
        $pdo->beginTransaction();
        
        // Dados da troca
        $produto_entrada_id = intval($_POST['produto_entrada_id']);
        $produto_saida_id = intval($_POST['produto_saida_id']);
        $quantidade_entrada = floatval($_POST['quantidade_entrada']);
        $quantidade_saida = floatval($_POST['quantidade_saida']);
        $forma_pagamento = $_POST['forma_pagamento_troca'];
        $valor_diferenca = floatval($_POST['valor_diferenca']);
        
        // Validar dados
        if ($produto_entrada_id <= 0 || $produto_saida_id <= 0 || $quantidade_entrada <= 0 || $quantidade_saida <= 0) {
            throw new Exception("Dados inválidos para a troca");
        }
        
        // Obter informações dos produtos
        $stmt = $pdo->prepare("SELECT id_produto, nome_produto, preco_venda, estoque_atual FROM produtos WHERE id_produto IN (?, ?)");
        $stmt->execute([$produto_entrada_id, $produto_saida_id]);
        $produtos = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (count($produtos) != 2) {
            throw new Exception("Um ou ambos os produtos não foram encontrados");
        }
        
        // Verificar estoque do produto de entrada
        if ($produtos[$produto_entrada_id]['estoque_atual'] < $quantidade_entrada) {
            throw new Exception("Estoque insuficiente para o produto de entrada");
        }
        
        // Calcular valores
        $valor_entrada = $produtos[$produto_entrada_id]['preco_venda'] * $quantidade_entrada;
        $valor_saida = $produtos[$produto_saida_id]['preco_venda'] * $quantidade_saida;
        $diferenca_calculada = $valor_entrada - $valor_saida;
        
        if (abs($diferenca_calculada - $valor_diferenca) > 0.01) {
            throw new Exception("Diferença de valor não confere");
        }
        
        // Registrar a venda de troca
        $dataVenda = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        
        $stmt = $pdo->prepare("INSERT INTO vendas 
            (cnpj_loja, usuario_vendedor, valor_subtotal, valor_total_venda, data_hora_venda, status_venda, tipo_venda) 
            VALUES (?, ?, ?, ?, ?, 'CONCLUIDA', 'TROCA')");
        $stmt->execute([
            $cnpj_loja, 
            $usuario, 
            $valor_entrada, 
            $valor_entrada, 
            $dataVenda
        ]);
        $id_venda_troca = $pdo->lastInsertId();
        
        // Adicionar itens à venda
        // Produto de entrada (o que o cliente está levando)
        $stmt = $pdo->prepare("INSERT INTO itens_venda 
            (id_venda, id_produto, sequencial_item, quantidade, preco_unitario_praticado, 
            valor_total_item, ncm_produto, cfop_produto) 
            VALUES (?, ?, 1, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $id_venda_troca,
            $produto_entrada_id,
            $quantidade_entrada,
            $produtos[$produto_entrada_id]['preco_venda'],
            $valor_entrada,
            '21050010', // NCM genérico - ajuste conforme necessário
            '5102'     // CFOP para venda
        ]);
        
        // Produto de saída (o que o cliente está devolvendo)
        $stmt = $pdo->prepare("INSERT INTO itens_venda 
            (id_venda, id_produto, sequencial_item, quantidade, preco_unitario_praticado, 
            valor_total_item, ncm_produto, cfop_produto, item_troca) 
            VALUES (?, ?, 2, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            $id_venda_troca,
            $produto_saida_id,
            $quantidade_saida,
            $produtos[$produto_saida_id]['preco_venda'],
            $valor_saida,
            '21050010', // NCM genérico - ajuste conforme necessário
            '5202',     // CFOP para devolução
        ]);
        
        // Registrar pagamento (se houver diferença)
        if ($valor_diferenca != 0) {
            $codigo_nfce = match($forma_pagamento) {
                'DINHEIRO' => '01',
                'CARTAO_CREDITO' => '03',
                'CARTAO_DEBITO' => '04',
                'PIX' => '17',
                default => '99'
            };
            
            $stmt = $pdo->prepare("INSERT INTO pagamentos_venda 
                (id_venda, forma_pagamento, meio_pagamento_nfce, valor_pago) 
                VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $id_venda_troca, 
                $forma_pagamento, 
                $codigo_nfce, 
                abs($valor_diferenca)
            ]);
        }
        
        // Atualizar estoques
        // Diminuir estoque do produto de entrada (o que o cliente está levando)
        $stmt = $pdo->prepare("UPDATE produtos SET estoque_atual = estoque_atual - ? WHERE id_produto = ?");
        $stmt->execute([$quantidade_entrada, $produto_entrada_id]);
        
        // Aumentar estoque do produto de saída (o que o cliente está devolvendo)
        $stmt = $pdo->prepare("UPDATE produtos SET estoque_atual = estoque_atual + ? WHERE id_produto = ?");
        $stmt->execute([$quantidade_saida, $produto_saida_id]);
        
        $pdo->commit();
        
        header("Location: caixa.php?msg=troca_sucesso&venda_troca=".$id_venda_troca);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: caixa.php?venda=".$venda_id."&msg=erro_troca&erro=".urlencode($e->getMessage()));
        exit;
    }
}

// Buscar últimas vendas com prepared statement - MODIFICADO PARA MOSTRAR CONCLUÍDAS E CANCELADAS
$stmt = $pdo->prepare("SELECT v.id_venda, v.data_hora_venda, v.valor_total_venda, v.usuario_vendedor, v.status_venda,
    GROUP_CONCAT(CONCAT(p.forma_pagamento, ': R$ ', FORMAT(p.valor_pago, 2)) SEPARATOR ' | ') AS pagamentos
    FROM vendas v
    LEFT JOIN pagamentos_venda p ON v.id_venda = p.id_venda
    WHERE v.cnpj_loja = ? AND v.status_venda IN ('CONCLUIDA', 'CANCELADA')
    GROUP BY v.id_venda
    ORDER BY v.data_hora_venda DESC
    LIMIT 7");

$stmt->execute([$cnpj_loja]);
$ultimas_vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Nova venda com transação
if (isset($_POST['nova_venda'])) {
    try {
        $pdo->beginTransaction();
        
        // Garante a data correta com timezone
        $dataVenda = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        
        $stmt = $pdo->prepare("INSERT INTO vendas 
            (cnpj_loja, usuario_vendedor, valor_subtotal, valor_total_venda, data_hora_venda) 
            VALUES (?, ?, 0, 0, ?)");
        $stmt->execute([$cnpj_loja, $usuario, $dataVenda]);
        
        $pdo->commit();
        header("Location: caixa.php?venda=" . $pdo->lastInsertId());
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: caixa.php?msg=erro_nova_venda");
        exit;
    }
}


// Abrir/fechar caixa com validação
if (isset($_POST['acao_caixa'])) {
    $acao = $_POST['acao_caixa'];

    if ($acao == 'abrir') {
        $valor_abertura = floatval($_POST['valor_abertura'] ?? 0);

        if ($valor_abertura < 0) {
            header("Location: caixa.php?msg=valor_invalido");
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO caixa 
                (data_abertura, valor_abertura, status, usuario, cnpj_loja) 
                VALUES (NOW(), ?, 'aberto', ?, ?)");
            $stmt->execute([$valor_abertura, $usuario, $cnpj_loja]);

            $pdo->commit();
            header("Location: caixa.php?msg=caixa_aberto");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: caixa.php?msg=erro_abrir_caixa");
            exit;
        }
    } elseif ($acao == 'fechar') {
        try {
            $pdo->beginTransaction();

            // Buscar o caixa aberto atual
            $stmt = $pdo->prepare("SELECT * FROM caixa WHERE status = 'aberto' AND cnpj_loja = ? ORDER BY data_abertura DESC LIMIT 1");
            $stmt->execute([$cnpj_loja]);
            $caixa = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$caixa) {
                $pdo->rollBack();
                header("Location: caixa.php?msg=nenhum_caixa_aberto");
                exit;
            }

            // Calcular totais do caixa
            $stmt = $pdo->prepare("SELECT 
                COALESCE(SUM(CASE WHEN forma_pagamento = 'DINHEIRO' THEN valor_pago ELSE 0 END), 0) AS total_dinheiro,
                COALESCE(SUM(CASE WHEN forma_pagamento = 'CARTAO_CREDITO' THEN valor_pago ELSE 0 END), 0) AS total_credito,
                COALESCE(SUM(CASE WHEN forma_pagamento = 'CARTAO_DEBITO' THEN valor_pago ELSE 0 END), 0) AS total_debito,
                COALESCE(SUM(CASE WHEN forma_pagamento = 'PIX' THEN valor_pago ELSE 0 END), 0) AS total_pix,
                COALESCE(SUM(valor_pago), 0) AS total_geral,
                COUNT(DISTINCT pv.id_venda) AS total_vendas
                FROM pagamentos_venda pv
                JOIN vendas v ON pv.id_venda = v.id_venda
                WHERE v.cnpj_loja = ? AND v.status_venda = 'CONCLUIDA' 
                AND v.data_hora_venda >= ?");
            $stmt->execute([$cnpj_loja, $caixa['data_abertura']]);
            $totais = $stmt->fetch(PDO::FETCH_ASSOC);

            $valor_fechamento = $totais['total_geral'] + $caixa['valor_abertura'];

            $stmt = $pdo->prepare("UPDATE caixa SET 
                status = 'fechado', 
                data_fechamento = NOW(),
                valor_fechamento = ?,
                total_dinheiro = ?,
                total_credito = ?,
                total_debito = ?,
                total_pix = ?,
                total_geral = ?,
                total_vendas = ?
                WHERE id = ?");
            $stmt->execute([
                $valor_fechamento,
                $totais['total_dinheiro'],
                $totais['total_credito'],
                $totais['total_debito'],
                $totais['total_pix'],
                $totais['total_geral'],
                $totais['total_vendas'],
                $caixa['id']
            ]);

            $pdo->commit();
    header("Location: imprimir_resumo_caixa.php?id_caixa=" . $caixa['id']);
    exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: caixa.php?msg=erro_fechar_caixa");
            exit;
        }
    }
}


// Verificar venda em aberto com validação - MODIFICADO
$venda_id = null;
if ($caixa_aberto) {
    $stmt = $pdo->prepare("SELECT id_venda FROM vendas 
                          WHERE cnpj_loja = ? AND usuario_vendedor = ? 
                          AND status_venda = 'EM_ABERTO' 
                          ORDER BY id_venda DESC LIMIT 1");
    $stmt->execute([$cnpj_loja, $usuario]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $venda_id = $row['id_venda'] ?? null;
}

// Se houver venda_id na URL, prioriza ela
if (isset($_GET['venda']) && is_numeric($_GET['venda'])) {
    $venda_id = intval($_GET['venda']);
    
    // Verifica se a venda pertence à loja
    $stmt = $pdo->prepare("SELECT id_venda FROM vendas WHERE id_venda = ? AND cnpj_loja = ?");
    $stmt->execute([$venda_id, $cnpj_loja]);
    if (!$stmt->fetch()) {
        $venda_id = null;
        header("Location: caixa.php?msg=venda_nao_encontrada");
        exit;
    }
}

// Carregar dados da venda e itens com validação
$venda = null;
$itens_venda = [];
$total_venda = 0;
$desconto_venda = 0;

if ($venda_id) {
    // Verificar se a venda pertence à loja
    $stmt = $pdo->prepare("SELECT * FROM vendas WHERE id_venda = ? AND cnpj_loja = ?");
    $stmt->execute([$venda_id, $cnpj_loja]);
    $venda = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$venda) {
        header("Location: caixa.php?msg=venda_nao_encontrada");
        exit;
    }

    $stmt = $pdo->prepare("SELECT iv.*, p.nome_produto, p.unidade_medida, p.referencia_interna, p.preco_venda, p.foto_produto 
    FROM itens_venda iv 
    JOIN produtos p ON iv.id_produto = p.id_produto 
    WHERE iv.id_venda = ? ORDER BY iv.sequencial_item");
    $stmt->execute([$venda_id]);
    $itens_venda = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($itens_venda as $item) {
        $total_venda += $item['valor_total_item'];
    }
    
    $desconto_venda = $venda['valor_desconto'] ?? 0;
    $total_venda -= $desconto_venda;
}

// Adicionar produto com validação de estoque
if ($venda_id && isset($_POST['add_produto_modal'])) {
    $id_produto = intval($_POST['id_produto']);
    $quantidade = floatval($_POST['quantidade'] ?? 1);
    
    if ($quantidade <= 0) {
        header("Location: caixa.php?venda=$venda_id&msg=quantidade_invalida");
        exit;
    }

    try {
        $pdo->beginTransaction();
        
        // Verificar produto e estoque
        $stmt = $pdo->prepare("SELECT * FROM produtos 
                             WHERE id_loja = ? AND id_produto = ? AND ativo = 1 
                             FOR UPDATE");
        $stmt->execute([$id_loja, $id_produto]);
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$produto) {
            throw new Exception("Produto não encontrado");
        }
        
        if ($produto['estoque_atual'] < $quantidade) {
            throw new Exception("Estoque insuficiente");
        }

        // Adicionar item à venda
        $stmt = $pdo->prepare("SELECT MAX(sequencial_item) FROM itens_venda WHERE id_venda = ?");
        $stmt->execute([$venda_id]);
        $seq = intval($stmt->fetchColumn() ?? 0) + 1;
        
        $valor_total = $quantidade * $produto['preco_venda'];
        
        $stmt = $pdo->prepare("INSERT INTO itens_venda 
            (id_venda, id_produto, sequencial_item, quantidade, preco_unitario_praticado, 
            valor_total_item, ncm_produto, cfop_produto) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $venda_id,
            $produto['id_produto'],
            $seq,
            $quantidade,
            $produto['preco_venda'],
            $valor_total,
            $produto['ncm'],
            $produto['cfop'] ?? '5102'
        ]);
        
        // Atualizar venda
        $stmt = $pdo->prepare("UPDATE vendas 
                              SET valor_subtotal = valor_subtotal + ?, 
                                  valor_total_venda = valor_total_venda + ? 
                              WHERE id_venda = ?");
        $stmt->execute([$valor_total, $valor_total, $venda_id]);
        
        // Atualizar estoque
        $stmt = $pdo->prepare("UPDATE produtos 
                              SET estoque_atual = estoque_atual - ? 
                              WHERE id_produto = ?");
        $stmt->execute([$quantidade, $produto['id_produto']]);
        
        $pdo->commit();
        header("Location: caixa.php?venda=$venda_id&msg=ok");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: caixa.php?venda=$venda_id&msg=erro_adicionar_produto");
        exit;
    }
}

// Adicionar produto por código de barras com validação
if ($venda_id && isset($_POST['adicionar_codigo'])) {
    $codigo_barras = trim($_POST['codigo_barras'] ?? '');
    
    if (empty($codigo_barras)) {
        header("Location: caixa.php?venda=$venda_id&msg=codigo_vazio");
        exit;
    }

    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM produtos 
                             WHERE id_loja = ? 
                             AND (codigo_barras_ean = ? OR referencia_interna = ?) 
                             AND ativo = 1 
                             FOR UPDATE");
        $stmt->execute([$id_loja, $codigo_barras, $codigo_barras]);
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$produto) {
            throw new Exception("Produto não encontrado");
        }
        
        if ($produto['estoque_atual'] < 1) {
            throw new Exception("Estoque insuficiente");
        }

        $stmt = $pdo->prepare("SELECT MAX(sequencial_item) FROM itens_venda WHERE id_venda = ?");
        $stmt->execute([$venda_id]);
        $seq = intval($stmt->fetchColumn() ?? 0) + 1;
        
        $valor_total = 1 * $produto['preco_venda'];
        
        $stmt = $pdo->prepare("INSERT INTO itens_venda 
            (id_venda, id_produto, sequencial_item, quantidade, preco_unitario_praticado, 
            valor_total_item, ncm_produto, cfop_produto) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $venda_id,
            $produto['id_produto'],
            $seq,
            1,
            $produto['preco_venda'],
            $valor_total,
            $produto['ncm'],
            $produto['cfop'] ?? '5102'
        ]);
        
        $stmt = $pdo->prepare("UPDATE vendas 
                              SET valor_subtotal = valor_subtotal + ?, 
                                  valor_total_venda = valor_total_venda + ? 
                              WHERE id_venda = ?");
        $stmt->execute([$valor_total, $valor_total, $venda_id]);
        
        $stmt = $pdo->prepare("UPDATE produtos 
                              SET estoque_atual = estoque_atual - 1 
                              WHERE id_produto = ?");
        $stmt->execute([$produto['id_produto']]);
        
        $pdo->commit();
        header("Location: caixa.php?venda=$venda_id&msg=ok");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: caixa.php?venda=$venda_id&msg=erro_codigo");
        exit;
    }
}

// Remover item com validação e transação
if ($venda_id && isset($_GET['remover_item'])) {
    $item_id = intval($_GET['remover_item']);
    
    try {
        $pdo->beginTransaction();
        
        // Obter item para restaurar estoque
        $stmt = $pdo->prepare("SELECT iv.id_produto, iv.quantidade, iv.valor_total_item 
                             FROM itens_venda iv
                             JOIN produtos p ON iv.id_produto = p.id_produto
                             WHERE iv.id_item_venda = ? AND iv.id_venda = ?
                             FOR UPDATE");
        $stmt->execute([$item_id, $venda_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            throw new Exception("Item não encontrado");
        }
        
        // Restaurar estoque
        $stmt = $pdo->prepare("UPDATE produtos 
                             SET estoque_atual = estoque_atual + ? 
                             WHERE id_produto = ?");
        $stmt->execute([$item['quantidade'], $item['id_produto']]);
        
        // Remover item
        $stmt = $pdo->prepare("DELETE FROM itens_venda 
                             WHERE id_item_venda = ? AND id_venda = ?");
        $stmt->execute([$item_id, $venda_id]);
        
        // Atualizar venda
        $stmt = $pdo->prepare("UPDATE vendas 
                             SET valor_subtotal = valor_subtotal - ?, 
                                 valor_total_venda = valor_total_venda - ? 
                             WHERE id_venda = ?");
        $stmt->execute([$item['valor_total_item'], $item['valor_total_item'], $venda_id]);
        
        $pdo->commit();
        header("Location: caixa.php?venda=$venda_id&msg=removido");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: caixa.php?venda=$venda_id&msg=erro_remover");
        exit;
    }
}

// Verificar se não há mais itens na venda
$stmt = $pdo->prepare("SELECT COUNT(*) FROM itens_venda WHERE id_venda = ?");
$stmt->execute([$venda_id]);
$total_itens = $stmt->fetchColumn();

if ($total_itens == 0) {
    // Se não há itens, zerar o desconto
    $stmt = $pdo->prepare("UPDATE vendas SET valor_desconto = 0, valor_total_venda = 0 WHERE id_venda = ?");
    $stmt->execute([$venda_id]);
}

// Aplicar desconto com validação
if ($venda_id && isset($_POST['aplicar_desconto'])) {
    $desconto = floatval($_POST['valor_desconto'] ?? 0);
    $tipo_desconto = $_POST['tipo_desconto'] ?? 'valor';
    
    if ($desconto <= 0) {
        header("Location: caixa.php?venda=$venda_id&msg=desconto_invalido");
        exit;
    }

    try {
        $pdo->beginTransaction();
        
        // Calcular subtotal
        $stmt = $pdo->prepare("SELECT SUM(valor_total_item) FROM itens_venda WHERE id_venda = ?");
        $stmt->execute([$venda_id]);
        $subtotal = $stmt->fetchColumn() ?? 0;
        
        if ($tipo_desconto == 'percentual') {
            $desconto_valor = $subtotal * ($desconto / 100);
        } else {
            $desconto_valor = $desconto;
        }
        
        // Limitar desconto ao valor máximo
        if ($desconto_valor > $subtotal) {
            $desconto_valor = $subtotal;
        }
        
        $stmt = $pdo->prepare("UPDATE vendas 
                             SET valor_desconto = ?, 
                                 valor_total_venda = valor_subtotal - ? 
                             WHERE id_venda = ?");
        $stmt->execute([$desconto_valor, $desconto_valor, $venda_id]);
        
        $pdo->commit();
        header("Location: caixa.php?venda=$venda_id&msg=desconto_aplicado");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: caixa.php?venda=$venda_id&msg=erro_desconto");
        exit;
    }
}

// Finalizar venda com validação completa e tratamento de NFC-e
if ($venda_id && isset($_POST['finalizar'])) {
    
    // =============================================
    // 1. VALIDAÇÃO DOS ITENS DA VENDA (OBRIGATÓRIO)
    // =============================================
    if (empty($itens_venda)) {
        header("Location: caixa.php?venda=$venda_id&msg=erro_finalizar");
        exit;
    }

    // =============================================
    // 2. CONFIGURAÇÃO DE FUSO HORÁRIO E LOGS
    // =============================================
    date_default_timezone_set('America/Sao_Paulo');
    
    // Log detalhado para depuração (registra no arquivo de log do PHP)
    error_log("[PDV DEBUG] Tentativa de finalização - Venda ID: $venda_id | " .
             "Data venda: " . $venda['data_hora_venda'] . " | " .
             "Data atual: " . date('Y-m-d H:i:s') . " | " .
             "Usuário: " . $usuario . " | " .
             "IP: " . $_SERVER['REMOTE_ADDR']);

    // =============================================

    
    try {
        $pdo->beginTransaction();
        
        // --- REGISTRAR FORMAS DE PAGAMENTO ---
        $formas_pagamento = $_POST['forma_pagamento'] ?? [];
        $valores = $_POST['valor_pago'] ?? [];
        $total_pago = 0;
        $pagamentos_registrados = [];
        
        foreach ($formas_pagamento as $index => $forma) {
            $valor = floatval($valores[$index] ?? 0);
            if ($valor > 0) {
                $total_pago += $valor;
                
                $codigo_nfce = match($forma) {
                    'DINHEIRO' => '01',
                    'CARTAO_CREDITO' => '03',
                    'CARTAO_DEBITO' => '04',
                    'PIX' => '17',
                    default => '99'
                };
                
                $stmt = $pdo->prepare("INSERT INTO pagamentos_venda 
                    (id_venda, forma_pagamento, meio_pagamento_nfce, valor_pago) 
                    VALUES (?, ?, ?, ?)");
                $stmt->execute([$venda_id, $forma, $codigo_nfce, $valor]);
                
                $pagamentos_registrados[] = [
                    'forma' => $forma,
                    'valor' => $valor,
                    'codigo_nfce' => $codigo_nfce
                ];
            }
        }
        
        // --- VALIDAR VALOR PAGO ---
        if ($total_pago <= 0) {
            throw new Exception("Nenhum valor de pagamento foi informado");
        }

        // --- ATUALIZAR STATUS DA VENDA ---
        $vendedor = $_POST['vendedor'] ?? $usuario;
        $stmt = $pdo->prepare("UPDATE vendas SET 
    status_venda = 'CONCLUIDA', 
    usuario_vendedor = ?,
    valor_total_venda = ?,
    valor_subtotal = ?,
    valor_desconto_geral = ?,
    data_conclusao = NOW(),
    data_hora_venda = NOW()
    WHERE id_venda = ?");
        
        $stmt->execute([
            $vendedor, 
            $total_venda,
            $total_venda + $desconto_venda,
            $desconto_venda,
            $venda_id
        ]);

// // --- EMISSÃO NFC-e ---
if (isset($_POST['emitir_nfce']) && $_POST['emitir_nfce'] == '1') {
    // Verificar se já não foi emitida NFC-e para esta venda
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM nfce WHERE id_venda = ?");
    $stmt->execute([$venda_id]);
    
    if ($stmt->fetchColumn() == 0) {
        $ambiente = ($loja['ambiente_nfce'] == 1) ? 1 : 2; // 1: produção, 2: homologação

        // Inserir cabeçalho da NFC-e (apenas colunas essenciais)
        $stmt = $pdo->prepare("INSERT INTO nfce 
            (id_venda, id_loja, ambiente_emissao, status_nfce, valor_total, data_solicitacao) 
            VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $venda_id, 
            $id_loja, 
            $ambiente,
            'PENDENTE_GERACAO',
            $total_venda
        ]);
        $id_nfce = $pdo->lastInsertId();

        if ($id_nfce) {
            // Registrar itens da NFC-e
            $stmt_itens = $pdo->prepare("SELECT 
                iv.id_produto, p.nome_produto, iv.quantidade, 
                iv.preco_unitario_praticado, iv.valor_total_item,
                p.ncm, p.cfop, p.unidade_medida
                FROM itens_venda iv
                JOIN produtos p ON iv.id_produto = p.id_produto
                WHERE iv.id_venda = ?");
            $stmt_itens->execute([$venda_id]);
            $itens_nfce = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt_insert_itens = $pdo->prepare("INSERT INTO nfce_itens
                (id_nfce, id_produto, nome_produto, quantidade, valor_unitario,
                valor_total, ncm, cfop, unidade_medida)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            foreach ($itens_nfce as $item) {
                $stmt_insert_itens->execute([
                    $id_nfce,
                    $item['id_produto'],
                    $item['nome_produto'],
                    $item['quantidade'],
                    $item['preco_unitario_praticado'],
                    $item['valor_total_item'],
                    $item['ncm'],
                    $item['cfop'] ?? '5102',
                    $item['unidade_medida']
                ]);
            }
        }
    }
}
        $pdo->commit();
        
        // --- REDIRECIONAMENTO ---
        if (isset($_POST['emitir_nfce']) && $_POST['emitir_nfce'] == '1') {
            // Passar mais parâmetros para a página de emissão
            header("Location: emitir_nfce.php?id_venda=$venda_id&vendedor=".urlencode($vendedor)."&total=".$total_venda);
        } else {
            header("Location: caixa.php?msg=concluida");
        }
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("ERRO FINALIZAÇÃO: " . $e->getMessage());
        header("Location: caixa.php?venda=$venda_id&msg=erro_finalizar&debug=".urlencode($e->getMessage()));
        exit;
    }
}

// Cancelar venda com validação e tratamento de NFC-e
if ($venda_id && isset($_POST['cancelar'])) {
    // --- VALIDAÇÃO INICIAL ---
    if (count($itens_venda) == 0) {
        header("Location: caixa.php?venda=$venda_id&msg=erro_cancelar");
        exit;
    }

    try {
        $pdo->beginTransaction();
        
        // --- VERIFICAR NFC-e EMITIDA ---
        $stmt_nfce = $pdo->prepare("SELECT id_nfce, status_nfce FROM nfce WHERE id_venda = ?");
        $stmt_nfce->execute([$venda_id]);
        $nfce = $stmt_nfce->fetch(PDO::FETCH_ASSOC);
        
        if ($nfce && $nfce['status_nfce'] == 'AUTORIZADA') {
            // Se NFC-e já foi autorizada, marcar para cancelamento
            $stmt = $pdo->prepare("UPDATE nfce SET 
                status_nfce = 'CANCELAMENTO_PENDENTE',
                motivo_cancelamento = 'Cancelamento solicitado pelo usuário',
                data_cancelamento_solicitado = NOW()
                WHERE id_nfce = ?");
            $stmt->execute([$nfce['id_nfce']]);
        }
        
        // --- RESTAURAR ESTOQUE ---
        $stmt = $pdo->prepare("SELECT id_produto, quantidade FROM itens_venda 
                             WHERE id_venda = ? FOR UPDATE");
        $stmt->execute([$venda_id]);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($itens as $item) {
            $stmt_upd = $pdo->prepare("UPDATE produtos 
                                     SET estoque_atual = estoque_atual + :qtd 
                                     WHERE id_produto = :id_produto");
            $stmt_upd->execute([
                'qtd' => $item['quantidade'],
                'id_produto' => $item['id_produto']
            ]);
        }
        
        // --- CANCELAR VENDA ---
        $vendedor = $_POST['vendedor'] ?? $usuario;
        $stmt = $pdo->prepare("UPDATE vendas 
                             SET status_venda = 'CANCELADA',
                             data_cancelamento = NOW(),
                             usuario_cancelamento = ?
                             WHERE id_venda = ?");
        $stmt->execute([$vendedor, $venda_id]);
        
        $pdo->commit();
        header("Location: caixa.php?msg=cancelada");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("ERRO CANCELAMENTO: " . $e->getMessage());
        header("Location: caixa.php?venda=$venda_id&msg=erro_cancelar&debug=".urlencode($e->getMessage()));
        exit;
    }
}

// Cancelar venda específica (da lista) com validação
    if (isset($_GET['cancelar_venda'])) {
        $id_venda_cancelar = intval($_GET['cancelar_venda']);
        
        try {
            $pdo->beginTransaction();
            
            // Verificar se a venda pertence à loja
            $stmt = $pdo->prepare("SELECT id_venda FROM vendas 
                                  WHERE id_venda = ? AND cnpj_loja = ? 
                                  AND status_venda = 'CONCLUIDA' 
                                  FOR UPDATE");
            $stmt->execute([$id_venda_cancelar, $cnpj_loja]);
            $venda = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$venda) {
                throw new Exception("Venda não encontrada");
            }
            
            // Restaurar estoque
            $stmt = $pdo->prepare("SELECT iv.id_produto, iv.quantidade 
                                 FROM itens_venda iv
                                 JOIN produtos p ON iv.id_produto = p.id_produto
                                 WHERE iv.id_venda = ?
                                 FOR UPDATE");
            $stmt->execute([$id_venda_cancelar]);
            $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // VERIFICAÇÃO IMPORTANTE:
            if (count($itens) == 0) {
                $pdo->rollBack();
                header("Location: caixa.php?msg=erro_cancelar");
                exit;
            }
            
            foreach ($itens as $item) {
                $stmt_upd = $pdo->prepare("UPDATE produtos 
                                         SET estoque_atual = estoque_atual + ? 
                                         WHERE id_produto = ?");
                $stmt_upd->execute([$item['quantidade'], $item['id_produto']]);
            }
            
            // Cancelar venda
            $stmt = $pdo->prepare("UPDATE vendas 
                                 SET status_venda = 'CANCELADA' 
                                 WHERE id_venda = ?");
            $stmt->execute([$id_venda_cancelar]);
            
            $pdo->commit();
            header("Location: caixa.php?msg=venda_cancelada");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: caixa.php?msg=erro_cancelar_venda");
            exit;
        }
    }

// Reimprimir NFC-e com validação
if (isset($_GET['reimprimir_nfce'])) {
    $id_venda_reimprimir = intval($_GET['reimprimir_nfce']);
    
    // Verificar se a NFC-e pertence à loja
    $stmt = $pdo->prepare("SELECT n.id_nfce 
                          FROM nfce n
                          JOIN vendas v ON n.id_venda = v.id_venda
                          WHERE n.id_venda = ? AND v.cnpj_loja = ?");
    $stmt->execute([$id_venda_reimprimir, $cnpj_loja]);
    $nfce = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($nfce) {
        header("Location: emitir_nfce.php?id_venda=$id_venda_reimprimir");
        exit;
    } else {
        header("Location: caixa.php?msg=erro_reimpressao&venda=$venda_id");
        exit;
    }
}

// Relatório de vendedores com validação de datas
if (isset($_POST['gerar_relatorio_vendedores'])) {
    $data_inicio = $_POST['data_inicio'] ?? date('Y-m-d');
    $data_fim = $_POST['data_fim'] ?? date('Y-m-d');
    
    if (strtotime($data_fim) < strtotime($data_inicio)) {
        header("Location: caixa.php?msg=datas_invalidas");
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT 
        usuario_vendedor,
        COUNT(*) as total_vendas,
        SUM(valor_total_venda) as valor_total
        FROM vendas
        WHERE cnpj_loja = ? 
        AND status_venda = 'CONCLUIDA'
        AND DATE(data_hora_venda) BETWEEN ? AND ?
        GROUP BY usuario_vendedor
        ORDER BY valor_total DESC");
    
    $stmt->execute([$cnpj_loja, $data_inicio, $data_fim]);
    $relatorio_vendedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Mensagens de feedback
$msg = $_GET['msg'] ?? '';
$msg_text = '';

$mensagens = [
    'ok' => ['type' => 'sucesso', 'text' => 'Produto adicionado!', 'icon' => 'check'],
    'erro' => ['type' => 'erro', 'text' => 'Produto não encontrado ou quantidade inválida.', 'icon' => 'times-circle'],
    'erro_codigo' => ['type' => 'erro', 'text' => 'Produto não encontrado para o código de barras informado.', 'icon' => 'times-circle'],
    'removido' => ['type' => 'removido', 'text' => 'Item removido!', 'icon' => 'trash'],
    'concluida' => ['type' => 'sucesso', 'text' => 'Venda finalizada!', 'icon' => 'check-double'],
    'cancelada' => ['type' => 'erro', 'text' => 'Venda cancelada!', 'icon' => 'ban'],
    'erro_finalizar' => ['type' => 'erro', 'text' => 'Não é possível finalizar venda sem itens.', 'icon' => 'times-circle'],
    'erro_cancelar' => ['type' => 'erro', 'text' => 'Não é possível cancelar venda sem itens.', 'icon' => 'times-circle'],
    'caixa_aberto' => ['type' => 'sucesso', 'text' => 'Caixa aberto com sucesso!', 'icon' => 'lock-open'],
    'caixa_fechado' => ['type' => 'sucesso', 'text' => 'Caixa fechado com sucesso!', 'icon' => 'lock'],
    'venda_cancelada' => ['type' => 'sucesso', 'text' => 'Venda cancelada com sucesso!', 'icon' => 'ban'],
    'erro_reimpressao' => ['type' => 'erro', 'text' => 'NFC-e não encontrada para reimpressão.', 'icon' => 'times-circle'],
    'vendedor_adicionado' => ['type' => 'sucesso', 'text' => 'Vendedor adicionado com sucesso!', 'icon' => 'user-plus'],
    'vendedor_removido' => ['type' => 'sucesso', 'text' => 'Vendedor removido com sucesso!', 'icon' => 'user-minus'],
    'vendedor_existente' => ['type' => 'erro', 'text' => 'Este vendedor já está cadastrado.', 'icon' => 'user-times'],
    'nao_remover_usuario' => ['type' => 'erro', 'text' => 'Você não pode remover a si mesmo.', 'icon' => 'exclamation-triangle'],
    'desconto_aplicado' => ['type' => 'sucesso', 'text' => 'Desconto aplicado com sucesso!', 'icon' => 'tag'],
    'valor_insuficiente' => ['type' => 'erro', 'text' => 'Valor pago é insuficiente para cobrir o total.', 'icon' => 'exclamation-circle'],
    'erro_adicionar' => ['type' => 'erro', 'text' => 'Erro ao adicionar vendedor.', 'icon' => 'times-circle'],
    'erro_remover' => ['type' => 'erro', 'text' => 'Erro ao remover vendedor.', 'icon' => 'times-circle'],
    'nome_vazio' => ['type' => 'erro', 'text' => 'Nome do vendedor não pode estar vazio.', 'icon' => 'times-circle'],
    'nome_curto' => ['type' => 'erro', 'text' => 'Nome do vendedor muito curto (mínimo 3 caracteres).', 'icon' => 'times-circle'],
    'erro_nova_venda' => ['type' => 'erro', 'text' => 'Erro ao criar nova venda.', 'icon' => 'times-circle'],
    'valor_invalido' => ['type' => 'erro', 'text' => 'Valor de abertura inválido.', 'icon' => 'times-circle'],
    'erro_abrir_caixa' => ['type' => 'erro', 'text' => 'Erro ao abrir caixa.', 'icon' => 'times-circle'],
    'erro_fechar_caixa' => ['type' => 'erro', 'text' => 'Erro ao fechar caixa.', 'icon' => 'times-circle'],
    'erro_adicionar_produto' => ['type' => 'erro', 'text' => 'produto zerado no estoque.', 'icon' => 'times-circle'],
    'codigo_vazio' => ['type' => 'erro', 'text' => 'Código de barras não pode estar vazio.', 'icon' => 'times-circle'],
    'erro_remover' => ['type' => 'erro', 'text' => 'Erro ao remover item.', 'icon' => 'times-circle'],
    'desconto_invalido' => ['type' => 'erro', 'text' => 'Valor de desconto inválido.', 'icon' => 'times-circle'],
    'erro_desconto' => ['type' => 'erro', 'text' => 'Erro ao aplicar desconto.', 'icon' => 'times-circle'],
    'venda_nao_encontrada' => ['type' => 'erro', 'text' => 'Venda não encontrada.', 'icon' => 'times-circle'],
    'erro_cancelar_venda' => ['type' => 'erro', 'text' => 'Erro ao cancelar venda.', 'icon' => 'times-circle'],
    'datas_invalidas' => ['type' => 'erro', 'text' => 'Datas inválidas para o relatório.', 'icon' => 'times-circle'],
    'quantidade_invalida' => ['type' => 'erro', 'text' => 'Quantidade inválida.', 'icon' => 'times-circle'],
 'troca_sucesso' => ['type' => 'sucesso', 'text' => 'Troca realizada com sucesso!', 'icon' => 'exchange-alt'],
'erro_troca' => ['type' => 'erro', 'text' => 'Erro ao processar troca: '.($_GET['erro'] ?? ''), 'icon' => 'times-circle'],
    'erro_data_venda' => ['type' => 'erro', 'text' => 'Não é possível finalizar venda de data diferente da atual.', 'icon' => 'times-circle']
];

if (isset($mensagens[$msg])) {
    $msg_data = $mensagens[$msg];
    $msg_text = "<div class='msg {$msg_data['type']}'><i class='fas fa-{$msg_data['icon']}'></i> {$msg_data['text']}</div>";
}

// Definir paginação
$pagina_atual = isset($_GET['pagina_produtos']) ? intval($_GET['pagina_produtos']) : 1;
$produtos_por_pagina = 10;
$offset = ($pagina_atual - 1) * $produtos_por_pagina;

// Buscar produtos ativos com paginação
$stmt = $pdo->prepare("SELECT id_produto, referencia_interna, nome_produto, preco_venda, unidade_medida, foto_produto 
                      FROM produtos 
                      WHERE id_loja = ? AND ativo = 1 
                      ORDER BY nome_produto
                      LIMIT ? OFFSET ?");
$stmt->execute([$id_loja, $produtos_por_pagina, $offset]);
$produtos_modal = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar total de produtos para calcular total de páginas
$stmt_total = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE id_loja = ? AND ativo = 1");
$stmt_total->execute([$id_loja]);
$total_produtos = $stmt_total->fetchColumn();
$total_paginas = ceil($total_produtos / $produtos_por_pagina);

// Buscar informações do caixa para exibir no modal
$info_caixa = null;
if ($caixa_aberto) {
    $stmt = $pdo->prepare("SELECT 
        COALESCE(SUM(CASE WHEN forma_pagamento = 'DINHEIRO' THEN valor_pago ELSE 0 END), 0) AS total_dinheiro,
        COALESCE(SUM(CASE WHEN forma_pagamento = 'CARTAO_CREDITO' THEN valor_pago ELSE 0 END), 0) AS total_credito,
        COALESCE(SUM(CASE WHEN forma_pagamento = 'CARTAO_DEBITO' THEN valor_pago ELSE 0 END), 0) AS total_debito,
        COALESCE(SUM(CASE WHEN forma_pagamento = 'PIX' THEN valor_pago ELSE 0 END), 0) AS total_pix,
        COALESCE(SUM(valor_pago), 0) AS total_geral,
        COUNT(DISTINCT pv.id_venda) AS total_vendas
        FROM pagamentos_venda pv
        JOIN vendas v ON pv.id_venda = v.id_venda
        WHERE v.cnpj_loja = ? AND v.status_venda = 'CONCLUIDA' 
        AND v.data_hora_venda >= ?");
    
    $stmt->execute([$cnpj_loja, $caixa['data_abertura']]);
    $info_caixa = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Caixa PDV - <?= htmlspecialchars($nome_loja) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Adicione isso no <head> -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
    :root {
        --primary: #4a6bff;
        --primary-dark: #2a4bcc;
        --primary-light: #eef2ff;
        --success: #10b981;
        --success-dark: #059669;
        --danger: #ef4444;
        --danger-dark: #dc2626;
        --warning: #f59e0b;
        --warning-dark: #d97706;
        --info: #3b82f6;
        --info-dark: #2563eb;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-300: #d1d5db;
        --gray-700: #374151;
        --gray-900: #111827;
        --sidebar: #1e293b;
        --sidebar-active: #334155;
        --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        --transition: all 0.3s ease;
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Poppins', sans-serif;
        background-color: #f8fafc;
        color: var(--gray-900);
        min-height: 100vh;
        overflow-x: hidden;
        padding-bottom: 80px; /* Espaço para os botões fixos */
    }
    
    /* Main Content */
    .main-content {
        min-height: 100vh;
        background: linear-gradient(135deg, #f5f7ff 0%, #eef2ff 100%);
        padding-bottom: 1rem;
    }
    
    /* Topbar */
    .topbar {
        background: white;
        padding: 0.8rem 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        position: sticky;
        top: 0;
        z-index: 100;
    }
    
    .topbar .info {
        font-size: 0.85rem;
        color: var(--gray-700);
    }
    
    .topbar .info strong {
        color: var(--primary-dark);
        font-weight: 600;
    }
    
    .close-btn {
        background: var(--danger);
        color: white;
        border: none;
        padding: 0.4rem 0.8rem;
        border-radius: 0.375rem;
        font-size: 0.85rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        transition: var(--transition);
        font-weight: 500;
    }
    
    .close-btn:hover {
        background: var(--danger-dark);
        transform: translateY(-2px);
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    .close-btn i {
        margin-right: 0.3rem;
    }
    
    /* PDV Container */
    .pdv-container {
        padding: 0 10px;
        max-width: 100%;
        margin: 0 auto;
        width: 100%;
    }
    
    .pdv-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        background: white;
        padding: 1rem;
        border-radius: 0.8rem;
        box-shadow: var(--card-shadow);
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .pdv-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--primary-dark);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .pdv-title i {
        font-size: 1.2rem;
        color: var(--primary);
    }
    
    .pdv-info {
        font-size: 0.9rem;
        color: var(--gray-700);
        margin-bottom: 1rem;
        background: white;
        padding: 1rem;
        border-radius: 0.6rem;
        box-shadow: var(--card-shadow);
    }
    
    .pdv-info b {
        color: var(--primary-dark);
    }
    
    /* Venda Actions */
    .venda-actions {
        display: flex;
        gap: 0.8rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }
    
    .btn-action {
        padding: 0.7rem 1.2rem;
        border-radius: 0.6rem;
        border: none;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        transition: var(--transition);
        font-size: 0.9rem;
        box-shadow: var(--card-shadow);
    }
    
    .btn-action.primary {
        background: var(--primary);
        color: white;
    }
    
    .btn-action.primary:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(74, 107, 255, 0.2);
    }
    
    .btn-action.secondary {
        background: var(--gray-200);
        color: var(--gray-900);
    }
    
    .btn-action.secondary:hover {
        background: var(--gray-300);
        transform: translateY(-2px);
    }
    
    .btn-action.info {
        background: var(--info);
        color: white;
    }
    
    .btn-action.info:hover {
        background: var(--info-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(59, 130, 246, 0.2);
    }
    
    /* Itens Table */
    .itens-container {
        background: white;
        border-radius: 0.8rem;
        overflow: hidden;
        box-shadow: var(--card-shadow);
        margin-bottom: 1rem;
        padding: 0;
        max-height: calc(100vh - 380px);
        overflow-y: auto;
    }
    
    .itens-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
    }
    
    .itens-table thead {
        background: var(--primary-light);
        position: sticky;
        top: 0;
        z-index: 10;
    }
    
    .itens-table th {
        padding: 0.8rem;
        text-align: left;
        font-weight: 600;
        color: var(--primary-dark);
        border-bottom: 2px solid var(--primary);
    }
    
    .itens-table td {
        padding: 0.8rem;
        border-bottom: 1px solid var(--gray-200);
    }
    
    .itens-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    .itens-table tbody tr:hover {
        background-color: var(--gray-100);
    }
    
    .itens-table tfoot {
        position: sticky;
        bottom: 0;
        background: white;
        z-index: 10;
        box-shadow: 0 -2px 5px rgba(0,0,0,0.1);
    }
    
    .itens-table tfoot td {
        font-weight: 700;
        background: var(--gray-100);
        padding: 0.8rem;
        text-align: right;
        font-size: 1rem;
    }
    
    .total-amount {
        color: var(--success-dark);
        font-size: 1.1rem;
    }
    
    .btn-remove {
        background: none;
        border: none;
        color: var(--danger);
        cursor: pointer;
        transition: var(--transition);
        font-size: 1rem;
    }
    
    .btn-remove:hover {
        color: var(--danger-dark);
        transform: scale(1.1);
    }
    
    /* Finalizar Venda */
    .finalizar-venda {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 1rem;
        flex-wrap: wrap;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: white;
        padding: 10px;
        border-top: 1px solid #ddd;
        z-index: 99;
    }
    
    .btn-finalizar {
        padding: 0.8rem 1.5rem;
        border-radius: 0.6rem;
        border: none;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        transition: var(--transition);
        min-width: 180px;
        justify-content: center;
        font-size: 0.95rem;
        box-shadow: var(--card-shadow);
    }
    
    .btn-finalizar.success {
        background: var(--success);
        color: white;
    }
    
    .btn-finalizar.success:hover {
        background: var(--success-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(16, 185, 129, 0.2);
    }
    
    .btn-finalizar.danger {
        background: var(--danger);
        color: white;
    }
    
    .btn-finalizar.danger:hover {
        background: var(--danger-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(239, 68, 68, 0.2);
    }
    
    .btn-finalizar:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none !important;
        box-shadow: none;
    }
    
    .btn-finalizar.secondary {
        background: var(--gray-200);
        color: var(--gray-900);
    }
    
    .btn-finalizar.secondary:hover {
        background: var(--gray-300);
        transform: translateY(-2px);
    }
    
    /* Nova Venda */
    .nova-venda-container {
        display: flex;
        justify-content: center;
        margin-top: 2rem;
        animation: fadeIn 1s ease;
    }
    
    .btn-nova-venda {
        padding: 1rem 2rem;
        border-radius: 0.6rem;
        background: var(--primary);
        color: white;
        border: none;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        transition: var(--transition);
        font-size: 1rem;
        box-shadow: var(--card-shadow);
    }
    
    .btn-nova-venda:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(74, 107, 255, 0.3);
    }
    
    /* Mensagens */
    .msg {
        padding: 0.8rem 1rem;
        border-radius: 0.6rem;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        animation: fadeIn 0.5s ease;
        font-weight: 500;
        box-shadow: var(--card-shadow);
        font-size: 0.9rem;
        position: relative;
        overflow: hidden;
    }
    
    .msg.sucesso {
    background: #d1fae5;
    color: #065f46;
    border-left: 4px solid #10b981;
    font-weight: bold;
}

.msg.erro {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #ef4444;
    font-weight: bold;
}

    
    .msg.removido {
        background: #fffbeb;
        color: #92400e;
        border: 1px solid #fde68a;
    }
    
    .msg::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        height: 4px;
        background: rgba(0,0,0,0.1);
        width: 100%;
        animation: progress 5s linear forwards;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    @keyframes progress {
        from { width: 100%; }
        to { width: 0%; }
    }
    
    /* Modal */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        backdrop-filter: blur(2px);
    }
    
    .modal-overlay.active {
        opacity: 1;
        visibility: visible;
    }
    
    .modal {
        background: white;
        border-radius: 1rem;
        width: 95%;
        max-width: 800px;
        max-height: 90vh;
        overflow: hidden;
        transform: translateY(20px);
        transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        display: flex;
        flex-direction: column;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }
    
    .modal-overlay.active .modal {
        transform: translateY(0);
    }
    
    .modal-header {
        padding: 1rem 1.5rem;
        background: var(--primary);
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-header h3 {
        font-size: 1.2rem;
        font-weight: 600;
    }
    
    .modal-close {
        background: none;
        border: none;
        color: white;
        font-size: 1.5rem;
        cursor: pointer;
        transition: var(--transition);
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }
    
    .modal-close:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: rotate(90deg);
    }
    
    .modal-body {
        padding: 1.5rem;
        overflow-y: auto;
        flex: 1;
    }
    
    /* Produtos Modal */
   /* Estilos específicos para o modal de produtos */
/* MODAL PRODUTOS - ESTILO COMPLETO */
.modal-produtos {
    max-width: 900px;
    width: 95%;
    height: 90vh;
    display: flex;
    flex-direction: column;
}

.modal-produtos .modal-header {
    background: linear-gradient(135deg, #4a6bff 0%, #2a4bcc 100%);
    color: white;
    padding: 15px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.modal-produtos .modal-body {
    padding: 0;
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
}

/* BARRA DE PESQUISA */
.produto-search-container {
    padding: 15px;
    background: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
    position: sticky;
    top: 0;
    z-index: 10;
}

.produto-search-box {
    display: flex;
    gap: 10px;
    align-items: center;
}

.produto-search-input {
    flex: 1;
    padding: 12px 15px;
    border: 2px solid #d1d5db;
    border-radius: 8px;
    font-size: 16px;
    transition: all 0.3s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.produto-search-input:focus {
    outline: none;
    border-color: #4a6bff;
    box-shadow: 0 0 0 3px rgba(74, 107, 255, 0.2);
}

.produto-search-btn {
    padding: 12px 20px;
    background: #4a6bff;
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
}

.produto-search-btn:hover {
    background: #3a5bef;
    transform: translateY(-1px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

/* GRADE DE PRODUTOS */
.produto-grid-container {
    padding: 15px;
    overflow-y: auto;
    flex: 1;
    background: #f5f7ff;
}

.produto-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
    padding: 5px;
}

.produto-card {
    display: flex;
    flex-direction: column;
    height: 360px;
    border: 1px solid #e0e0e0;
    border-radius: 10px;
    overflow: hidden;
    transition: all 0.3s ease;
    background: white;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    position: relative;
}

.produto-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    border-color: #4a6bff;
}

/* CONTAINER DA IMAGEM */
.produto-img-container {
    height: 180px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fa;
    border-bottom: 1px solid #eee;
    position: relative;
    overflow: hidden;
}

.produto-img {
    max-width: 95%;
    max-height: 95%;
    object-fit: contain;
    transition: transform 0.3s ease;
}

.produto-card:hover .produto-img {
    transform: scale(1.05);
}

.produto-sem-imagem {
    color: #d1d5db;
    font-size: 40px;
}

/* INFORMAÇÕES DO PRODUTO */
.produto-info-container {
    padding: 12px;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.produto-nome {
    flex: 1;
    font-size: 16px;
    line-height: 1.4;
    margin-bottom: 8px;
    color: #333;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    word-break: break-word;
    min-height: auto;
    position: relative;
        padding: 5px 0; /* Adicionei padding */

}

.produto-nome:hover::after {
    content: attr(title);
    position: absolute;
    bottom: 100%;
    left: 0;
    background: rgba(0,0,0,0.9);
    color: white;
    padding: 8px 12px;
    border-radius: 6px;
    z-index: 100;
    width: 100%;
    max-width: 250px;
    word-wrap: break-word;
    font-size: 13px;
    line-height: 1.4;
    pointer-events: none;
    opacity: 0;
    transform: translateY(-5px);
    transition: all 0.3s ease;
}

.produto-nome:hover::after {
    opacity: 1;
    transform: translateY(0);
}

.produto-codigo {
    font-size: 16px;
    color: #666;
    margin-bottom: 8px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
        font-weight: 500; /* Adicionei peso da fonte */

}

.produto-preco {
    font-weight: 700;
    font-size: 18px;
    color: #2a4bcc;
    margin: 10px 0;
}

/* QUANTIDADE E BOTÃO */
.produto-quantidade-container {
    margin: 8px 0;
}

.produto-quantidade-label {
    display: block;
    font-size: 12px;
    color: #666;
    margin-bottom: 4px;
}

.produto-quantidade {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    text-align: center;
    transition: all 0.3s ease;
}

.produto-quantidade:focus {
    outline: none;
    border-color: #4a6bff;
    box-shadow: 0 0 0 2px rgba(74, 107, 255, 0.1);
}

.btn-add-produto {
    width: 100%;
    padding: 10px;
    background: #10b981;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-top: auto;
}

.btn-add-produto:hover {
    background: #0d9e6e;
    transform: translateY(-2px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.btn-add-produto:disabled {
    background: #d1d5db;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* PAGINAÇÃO */
.produto-pagination {
    padding: 15px;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    background: white;
    border-top: 1px solid #e0e0e0;
    position: sticky;
    bottom: 0;
    z-index: 10;
}

.produto-pagination-btn {
    padding: 10px 16px;
    background: #4a6bff;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 500;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.produto-pagination-btn:hover:not(:disabled) {
    background: #3a5bef;
    transform: translateY(-1px);
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.produto-pagination-btn:disabled {
    background: #d1d5db;
    cursor: not-allowed;
    opacity: 0.6;
}

.produto-pagination-info {
    padding: 8px 12px;
    font-size: 14px;
    color: #666;
    font-weight: 500;
}

/* RESPONSIVIDADE */
@media (max-width: 768px) {
    .modal-produtos {
        width: 98%;
        height: 95vh;
    }
    
    .produto-grid {
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    }
    
    .produto-search-box {
        flex-direction: column;
    }
    
    .produto-search-btn {
        width: 100%;
        justify-content: center;
    }
    
    .produto-img-container {
        height: 120px;
    }
    
    .produto-nome {
        min-height: 50px;
        -webkit-line-clamp: 2;
    }
}

@media (max-width: 480px) {
    .produto-grid {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    
    .produto-card {
        height: 280px;
    }
    
    .produto-img-container {
        height: 100px;
    }
    
    .produto-info-container {
        padding: 8px;
    }
    
    .produto-nome {
        font-size: 13px;
        min-height: 40px;
    }
    
    .produto-pagination {
        flex-wrap: wrap;
    }
}

/* ANIMAÇÕES */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.produto-card {
    animation: fadeIn 0.3s ease forwards;
    opacity: 0;
}

.produto-card:nth-child(1) { animation-delay: 0.1s; }
.produto-card:nth-child(2) { animation-delay: 0.15s; }
.produto-card:nth-child(3) { animation-delay: 0.2s; }
.produto-card:nth-child(4) { animation-delay: 0.25s; }
.produto-card:nth-child(5) { animation-delay: 0.3s; }
.produto-card:nth-child(6) { animation-delay: 0.35s; }
.produto-card:nth-child(7) { animation-delay: 0.4s; }
.produto-card:nth-child(8) { animation-delay: 0.45s; }
.produto-card:nth-child(9) { animation-delay: 0.5s; }
.produto-card:nth-child(10) { animation-delay: 0.55s; }
    
    /* Modal Vendedor */
    .vendedor-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 0.8rem;
        margin-top: 1rem;
    }
    
    .vendedor-card {
        border: 1px solid var(--gray-200);
        border-radius: 0.6rem;
        padding: 1rem;
        transition: var(--transition);
        display: flex;
        flex-direction: column;
        align-items: center;
        background: white;
        box-shadow: var(--card-shadow);
        cursor: pointer;
        position: relative;
    }
    
    .vendedor-card:hover {
        border-color: var(--primary);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }
    
    .vendedor-card.selected {
        border: 2px solid var(--primary);
        background: var(--primary-light);
    }
    
    .vendedor-icon {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: var(--primary-light);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 0.8rem;
        font-size: 1.2rem;
        color: var(--primary);
    }
    
    .vendedor-name {
        font-weight: 600;
        text-align: center;
        color: var(--gray-900);
        font-size: 0.9rem;
    }
    
    .vendedor-actions {
        position: absolute;
        top: 0.3rem;
        right: 0.3rem;
    }
    
    .btn-delete {
        background: none;
        border: none;
        color: var(--danger);
        cursor: pointer;
        transition: var(--transition);
        font-size: 0.9rem;
        padding: 0.2rem;
    }
    
    .btn-delete:hover {
        color: var(--danger-dark);
        transform: scale(1.1);
    }
    
    .add-vendedor {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 1rem;
        padding: 0.8rem;
        background: var(--gray-100);
        border-radius: 0.6rem;
    }
    
    .add-vendedor input {
        flex: 1;
        padding: 0.7rem;
        border: 1px solid var(--gray-300);
        border-radius: 0.4rem;
        font-size: 0.9rem;
    }
    
    .add-vendedor button {
        padding: 0.7rem 1rem;
        background: var(--success);
        color: white;
        border: none;
        border-radius: 0.4rem;
        cursor: pointer;
        transition: var(--transition);
        font-size: 0.9rem;
    }
    
    .add-vendedor button:hover {
        background: var(--success-dark);
    }
    
    /* Modal Pagamento */
    .pagamento-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
    }
    
    .pagamento-info {
        background: var(--gray-100);
        border-radius: 0.8rem;
        padding: 1rem;
    }
    
    .pagamento-info-item {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px solid var(--gray-200);
        font-size: 0.9rem;
    }
    
    .pagamento-info-item:last-child {
        border-bottom: none;
    }
    
    .pagamento-formas {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .forma-pagamento {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        background: white;
        border-radius: 0.6rem;
        padding: 0.8rem;
        box-shadow: var(--card-shadow);
        border: 2px solid transparent;
        transition: var(--transition);
        cursor: pointer;
    }
    
    .forma-pagamento:hover {
        border-color: var(--primary);
        transform: translateY(-2px);
    }
    
    .forma-pagamento.selected {
        border-color: var(--primary);
        background: var(--primary-light);
    }
    
    .forma-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--primary-light);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        color: var(--primary);
    }
    
    .forma-details {
        flex: 1;
    }
    
    .forma-name {
        font-weight: 600;
        margin-bottom: 0.2rem;
        font-size: 0.9rem;
    }
    
    .forma-value {
        font-weight: 500;
        color: var(--gray-700);
        font-size: 0.85rem;
    }
    
    .valor-input {
        width: 100px;
        padding: 0.6rem;
        border: 1px solid var(--gray-300);
        border-radius: 0.4rem;
        font-size: 0.9rem;
        transition: var(--transition);
    }
    
    .valor-input:focus {
        outline: none;
        border-color: var(--primary);
    }
    
    .btn-confirmar {
        padding: 0.8rem;
        background: var(--primary);
        color: white;
        border: none;
        border-radius: 0.6rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        width: 100%;
        margin-top: 1rem;
        font-size: 1rem;
    }
    
    .btn-confirmar:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
    }
    
    /* Status do Caixa */
    .caixa-status {
        padding: 0.6rem 1rem;
        border-radius: 0.6rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
        box-shadow: var(--card-shadow);
        font-size: 0.9rem;
    }
    
    .caixa-status.aberto {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #34d399;
    }
    
    .caixa-status.fechado {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #f87171;
    }
    
    .resumo-caixa {
        background: var(--gray-100);
        border-radius: 0.8rem;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .resumo-item {
        display: flex;
        justify-content: space-between;
        padding: 0.4rem 0;
        border-bottom: 1px solid var(--gray-200);
        font-size: 0.9rem;
    }
    
    .resumo-item:last-child {
        border-bottom: none;
        font-weight: bold;
        margin-top: 0.4rem;
        padding-top: 0.4rem;
        border-top: 1px solid var(--gray-300);
    }
    
    .venda-status {
        padding: 0.2rem 0.4rem;
        border-radius: 0.4rem;
        font-size: 0.75rem;
        font-weight: 500;
    }
    
    .status-concluida {
        background: #d1fae5;
        color: #065f46;
    }
    
    .status-cancelada {
        background: #fee2e2;
        color: #991b1b;
    }
    
    .status-aberta {
        background: #fef3c7;
        color: #92400e;
    }
    
    .venda-actions {
        display: flex;
        gap: 0.4rem;
    }
    
    .venda-action-btn {
        padding: 0.2rem 0.4rem;
        border-radius: 0.4rem;
        border: none;
        cursor: pointer;
        transition: var(--transition);
        font-size: 0.8rem;
    }
    
    .venda-action-btn.primary {
        background: var(--primary);
        color: white;
    }
    
    .venda-action-btn.primary:hover {
        background: var(--primary-dark);
    }
    
    .venda-action-btn.danger {
        background: var(--danger);
        color: white;
    }
    
    .venda-action-btn.danger:hover {
        background: var(--danger-dark);
    }
    
    /* Modal Desconto */
    .desconto-container {
        max-width: 500px;
        margin: 0 auto;
    }
    
    .desconto-form {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .desconto-options {
        display: flex;
        gap: 0.8rem;
        margin-bottom: 0.8rem;
    }
    
    .desconto-option {
        flex: 1;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.9rem;
    }
    
    .desconto-input {
        width: 100%;
        padding: 0.8rem 1rem;
        border-radius: 0.6rem;
        border: 1px solid var(--gray-300);
        font-size: 0.9rem;
        transition: var(--transition);
    }
    
    .desconto-input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(74, 107, 255, 0.1);
    }
    
    /* Modal Relatório Vendedores */
    .relatorio-container {
        max-width: 800px;
        margin: 0 auto;
    }
    
    .relatorio-form {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .date-inputs {
        display: flex;
        gap: 0.8rem;
    }
    
    .date-input {
        flex: 1;
        padding: 0.8rem 1rem;
        border-radius: 0.6rem;
        border: 1px solid var(--gray-300);
        font-size: 0.9rem;
        transition: var(--transition);
    }
    
    .date-input:focus {
        outline: none;
        border-color: var(--primary);
    }
    
    .relatorio-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
        font-size: 0.85rem;
    }
    
    .relatorio-table th {
        background: var(--primary);
        color: white;
        padding: 0.8rem;
        text-align: left;
    }
    
    .relatorio-table td {
        padding: 0.6rem;
        border-bottom: 1px solid var(--gray-200);
    }
    
    .relatorio-table tr:nth-child(even) {
        background: var(--gray-100);
    }
    
    .relatorio-table tr:hover {
        background: var(--primary-light);
    }
    
    /*--- [NOVO CSS ADICIONAL - CÓDIGO DE BARRAS E TABELA VENDAS ANTERIORES] ---*/
    /* Novo estilo para o campo de código de barras */
    .codigo-barras-container {
        margin-bottom: 1rem;
        background: white;
        padding: 1rem;
        border-radius: 0.8rem;
        box-shadow: var(--card-shadow);
        animation: fadeIn 0.5s ease;
    }
    
    .codigo-barras-form {
        display: flex;
        gap: 0.8rem;
    }
    
    .codigo-barras-input {
        flex: 1;
        padding: 0.8rem 1rem;
        border-radius: 0.6rem;
        border: 2px solid var(--primary);
        font-size: 1rem;
        transition: var(--transition);
    }
    
    .codigo-barras-input:focus {
        outline: none;
        border-color: var(--primary-dark);
        box-shadow: 0 0 0 3px rgba(74, 107, 255, 0.2);
    }
    
    .btn-adicionar-codigo {
        padding: 0.8rem 1.2rem;
        background: var(--primary);
        color: white;
        border: none;
        border-radius: 0.6rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        font-size: 1rem;
    }
    
    .btn-adicionar-codigo:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
    }
    
    /* Melhorias na tabela de vendas anteriores */
    .vendas-anteriores-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
        font-size: 0.85rem;
    }
    
    .vendas-anteriores-table th {
        background: var(--primary);
        color: white;
        padding: 0.8rem;
        text-align: left;
        position: sticky;
        top: 0;
    }
    
    .vendas-anteriores-table td {
        padding: 0.6rem 0.8rem;
        border-bottom: 1px solid var(--gray-200);
    }
    
    .vendas-anteriores-table tr:nth-child(even) {
        background: var(--gray-100);
    }
    
    .vendas-anteriores-table tr:hover {
        background: var(--primary-light);
    }
    
    .vendas-anteriores-container {
        max-height: 60vh;
        overflow-y: auto;
    }
    
    /*--- [FIM NOVO CSS ADICIONAL] ---*/
    
    /* Imagens de produtos */
    .produto-img-container {
        width: 100%;
        height: 100px;
        background: #f3f4f6;
        border-radius: 8px;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }
    
    .produto-img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }
    
    /* Responsividade */
    @media (max-width: 1200px) {
        .pagamento-container {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 768px) {
        .pdv-header {
            flex-direction: column;
            gap: 0.8rem;
            align-items: flex-start;
        }
        
        .venda-actions {
            flex-direction: column;
        }
        
        .btn-action {
            width: 100%;
        }
        
        .finalizar-venda {
            flex-direction: column;
        }
        
        .btn-finalizar {
            width: 100%;
            min-width: auto;
        }
        
        .produto-grid {
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        }
        
        .vendedor-grid {
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        }
        
        .date-inputs {
            flex-direction: column;
        }
    }
    
    @media (max-width: 480px) {
        .topbar {
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-start;
        }
        
        .topbar .info {
            font-size: 0.8rem;
        }
        
        .pdv-title {
            font-size: 1.2rem;
        }
        
        .produto-grid {
            grid-template-columns: 1fr;
        }
        
        .vendedor-grid {
            grid-template-columns: 1fr;
        }
        
        .itens-table {
            font-size: 0.8rem;
        }
        
        .itens-table th, .itens-table td {
            padding: 0.5rem;
        }

        .itens-container {
            max-height: calc(100vh - 420px);
        }
    }

    /* ===== ADICIONE O SPINNER AQUI NO FINAL ===== */
    /* Loading Spinner */
    .spinner {
        width: 40px;
        height: 40px;
        margin: 0 auto;
        border: 4px solid rgba(0, 0, 0, 0.1);
        border-radius: 50%;
        border-top-color: var(--primary);
        animation: spin 0.6s linear infinite;
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 9999;
    }

    @keyframes spin {
        to { transform: translate(-50%, -50%) rotate(360deg); }
    }

    .overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(255, 255, 255, 0.7);
        z-index: 9998;
        display: none;
    }

    .loading-message {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(0,0,0,0.8);
    color: white;
    padding: 1rem 2rem;
    border-radius: 0.5rem;
    z-index: 10000;
    display: none;
    animation: fadeIn 0.3s ease;
}

.loading-message.success {
    background: var(--success);
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.btn-cancelar:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    background-color: #ccc !important;
}

.venda-status {
    padding: 0.2rem 0.5rem;
    border-radius: 0.3rem;
    font-size: 0.8rem;
    font-weight: 500;
    display: inline-block;
}

.status-concluida {
    background-color: #d1fae5;
    color: #065f46;
}

.status-cancelada {
    background-color: #fee2e2;
    color: #991b1b;
}

/* Adicione no seu <style> */
/* Garante que o SweetAlert2 apareça acima de tudo */
.swal2-container {
    z-index: 99999 !important;
}

/* Ajusta a tabela de últimas vendas */
.vendas-anteriores-container {
    position: relative; /* Remove se já estiver definido */
    z-index: 1; /* Valor baixo para não interferir */
    overflow: visible; /* Importante para não cortar elementos */
}

/* Ajusta o modal de últimas vendas */
#modalUltimasVendas .modal {
    z-index: 100;
    position: relative;
}

/* Garante que o fundo do SweetAlert2 cubra tudo */
.swal2-backdrop-show {
    background: rgba(0,0,0,0.7) !important;
}

.modal-overlay {
    transition: opacity 0.3s ease, visibility 0.3s ease;
}

.modal {
    transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275), opacity 0.3s ease;
}

.relatorio-table th:nth-child(3),
.relatorio-table td:nth-child(3) {
    text-align: center;
}

.relatorio-table th:nth-child(4),
.relatorio-table td:nth-child(4),
.relatorio-table th:nth-child(5),
.relatorio-table td:nth-child(5),
.relatorio-table th:nth-child(6),
.relatorio-table td:nth-child(6) {
    text-align: right;
}

/* Adicione no seu <style> *
    /* ===== FIM DO SPINNER ===== */
</style>
</head>
<body>
    <!-- Main Content -->
    <div class="main-content">
        <!-- Topbar com data/hora real -->
        <div class="topbar">
            <div class="info">
                <span id="currentDateTime"></span> | 
                Usuário: <strong><?= htmlspecialchars($usuario) ?></strong> | 
                Loja: <strong><?= htmlspecialchars($nome_loja) ?></strong> | 
                CNPJ: <strong><?= htmlspecialchars($cnpj_loja) ?></strong>
            </div>
            <a href="dashboard.php" class="close-btn">
                <i class="fas fa-times"></i> Fechar
            </a>
        </div>

        <!-- PDV Container -->
        <div class="pdv-container">
            <div class="pdv-header">
                <h1 class="pdv-title">
                    <i class="fas fa-cash-register"></i> Caixa PDV
                </h1>
                <div style="display: flex; gap: 0.8rem; flex-wrap: wrap;">
                    <button type="button" class="btn-action info" onclick="abrirModalTotalVendas()">
                        <i class="fas fa-chart-pie"></i> Total de Vendas
                    </button>
                        <button type="button" class="btn-action danger" onclick="abrirModalSangria()">
        <i class="fas fa-money-bill-wave"></i> Sangria
    </button>

                    <button type="button" class="btn-action info" onclick="abrirModalRelatorioVendedores()">
                        <i class="fas fa-chart-bar"></i> Relatório Vendedores
                    </button>
                    <button type="button" class="btn-action <?= $caixa_aberto ? 'danger' : 'success' ?>" 
                        onclick="abrirModalCaixa()">
                        <i class="fas <?= $caixa_aberto ? 'fa-lock' : 'fa-lock-open' ?>"></i> 
                        <?= $caixa_aberto ? 'Fechar Caixa' : 'Abrir Caixa' ?>
                    </button>
                </div>
            </div>

            <div class="caixa-status <?= $caixa_aberto ? 'aberto' : 'fechado' ?>">
                <i class="fas <?= $caixa_aberto ? 'fa-lock-open' : 'fa-lock' ?>"></i>
                <span>Caixa <?= $caixa_aberto ? 'ABERTO' : 'FECHADO' ?> 
                <?= $caixa_aberto ? 'desde ' . date('d/m/Y H:i', strtotime($caixa['data_abertura'])) : '' ?></span>
            </div>

            <?= $msg_text ?>

            <?php if (!$caixa_aberto): ?>
                <!-- Caixa fechado -->
                <div class="nova-venda-container">
                    <div style="text-align: center; padding: 1.5rem; background: white; border-radius: 0.8rem; box-shadow: var(--card-shadow);">
                        <i class="fas fa-lock" style="font-size: 2.5rem; color: var(--danger); margin-bottom: 0.8rem;"></i>
                        <h2 style="margin-bottom: 0.8rem; font-size: 1.3rem;">Caixa Fechado</h2>
                        <p style="margin-bottom: 1.2rem; font-size: 0.95rem;">Para iniciar uma nova venda, é necessário abrir o caixa.</p>
                        <button type="button" class="btn-nova-venda" onclick="abrirModalCaixa()">
                            <i class="fas fa-lock-open"></i> Abrir Caixa
                        </button>
                    </div>
                </div>
            <?php elseif (!$venda_id): ?>
                <!-- Nova Venda -->
                <div class="nova-venda-container">
                    <form method="post">
                        <button type="submit" name="nova_venda" class="btn-nova-venda" <?= !$caixa_aberto ? 'disabled' : '' ?>>
                            <i class="fas fa-plus"></i> Iniciar Nova Venda
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <!-- Campo para leitura de código de barras -->
                <div class="codigo-barras-container">
    <form method="post" class="codigo-barras-form" id="codigoForm">
        <input type="text" 
               name="codigo_barras" 
               class="codigo-barras-input" 
               placeholder="Escaneie ou digite o código (adiciona automaticamente)" 
               autocomplete="off"
               autofocus
               required
               id="codigoInput">
        <!-- Campo oculto para substituir o botão -->
        <input type="hidden" name="adicionar_codigo" value="1">
    </form>
</div>


                <!-- Ações da Venda -->
                <div class="venda-actions">
                    <button type="button" class="btn-action primary" onclick="abrirModalProdutos()" <?= !$caixa_aberto ? 'disabled' : '' ?>>
                        <i class="fas fa-box-open"></i> Adicionar Produtos
                    </button>
                    
                    <button type="button" class="btn-action secondary" onclick="abrirModalUltimasVendas()">
                        <i class="fas fa-receipt"></i> Ver Vendas Anteriores
                    </button>
<button type="button" class="btn-action warning" onclick="abrirModalTroca()">
    <i class="fas fa-exchange-alt"></i> Troca de Produto
</button>
                    <button type="button" class="btn-action info" onclick="abrirModalDesconto()" <?= empty($itens_venda) ? 'disabled' : '' ?>>
                        <i class="fas fa-tag"></i> Aplicar Desconto
                    </button>
                </div>

                <!-- Tabela de Itens -->
                <div class="itens-container">
                    <table class="itens-table">
                        <thead>
                            <tr>
                                <th>Foto</th>
                                <th>Item</th>
                                <th>Código</th>
                                <th>Produto</th>
                                <th>Qtd</th>
                                <th>Un</th>
                                <th>V. Unit.</th>
                                <th>Total</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($itens_venda)): ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 5rem 1rem; color: var(--gray-700);">
                                        <i class="fas fa-shopping-cart" style="font-size: 2rem; margin-bottom: 0.8rem; opacity: 0.3;"></i>
                                        <p style="font-size: 0.9rem;">Nenhum produto adicionado à venda</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($itens_venda as $item): ?>
                                    <tr>
                                        <td>
                                            <div class="produto-img-container">
                                                <?php if (!empty($item['foto_produto']) && file_exists('uploads/imagens/'.$item['foto_produto'])): ?>
                                                    <img src="uploads/imagens/<?= htmlspecialchars($item['foto_produto']) ?>" 
                                                         class="produto-img" 
                                                         alt="<?= htmlspecialchars($item['nome_produto']) ?>">
                                                <?php else: ?>
                                                    <i class="fas fa-image" style="color: #d1d5db; font-size: 2rem;"></i>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?= $item['sequencial_item'] ?></td>
                                        <td><?= htmlspecialchars($item['referencia_interna']) ?></td>
                                        <td><?= htmlspecialchars($item['nome_produto']) ?></td>
                                        <td><?= number_format($item['quantidade'], 3, ',', '.') ?></td>
                                        <td><?= htmlspecialchars($item['unidade_medida']) ?></td>
                                        <td>R$ <?= number_format($item['preco_unitario_praticado'], 2, ',', '.') ?></td>
                                        <td>R$ <?= number_format($item['valor_total_item'], 2, ',', '.') ?></td>
                                        <td>
                                            <button type="button"
    class="btn-remove"
    title="Remover"
    data-item-id="<?= $item['id_item_venda'] ?>"
    <?= !$caixa_aberto ? 'disabled' : '' ?>>
    <i class="fas fa-trash-alt"></i>
</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" style="text-align: right; font-size: 1rem;">Subtotal:</td>
                                <td colspan="3">
                                    R$ <?= number_format($total_venda + $desconto_venda, 2, ',', '.') ?>
                                </td>
                            </tr>
                            <?php if ($desconto_venda > 0): ?>
                            <tr>
                                <td colspan="6" style="text-align: right; font-size: 1rem;">Desconto:</td>
                                <td colspan="3" style="color: var(--danger);">
                                    - R$ <?= number_format($desconto_venda, 2, ',', '.') ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td colspan="6" style="text-align: right; font-size: 1rem;">Total:</td>
                                <td colspan="3" class="total-amount">
                                    R$ <?= number_format($total_venda, 2, ',', '.') ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Finalizar Venda -->
                <div class="finalizar-venda">
                    <form method="post" id="formFinalizarVenda">
    <input type="hidden" name="finalizar" value="1">
    <button type="button" class="btn-finalizar success" id="btnFinalizar" <?= empty($itens_venda) || !$caixa_aberto ? 'disabled' : '' ?>>
        <i class="fas fa-check-circle"></i> Finalizar Venda
    </button>
</form>
<form method="post" id="formCancelarVenda">
    <input type="hidden" name="cancelar" value="1">
    <button type="button" class="btn-finalizar danger" id="btnCancelar" <?= empty($itens_venda) || !$caixa_aberto ? 'disabled' : '' ?>>
        <i class="fas fa-ban"></i> Cancelar Venda
    </button>
</form>
                    <a href="caixa.php" class="btn-finalizar secondary">
                        <i class="fas fa-redo"></i> Nova Venda
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal-overlay" id="modalProdutos">
    <div class="modal modal-produtos">
        <div class="modal-header">
            <h3>Selecionar Produto</h3>
            <button class="modal-close" onclick="fecharModalProdutos()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="produto-search-container">
                <div class="produto-search-box">
                    <input type="text" 
                           id="pesquisaProduto" 
                           class="produto-search-input" 
                           placeholder="Pesquisar produto por nome ou código..."
                           autocomplete="off"
                           autofocus>
                    <button type="button" class="produto-search-btn" onclick="filtrarProdutos()">
                        <i class="fas fa-search"></i> Buscar
                    </button>
                </div>
            </div>
            
            <!-- Área rolável dos produtos -->
            <div class="produto-grid-container" id="produtoGridContainer">
                <!-- Conteúdo carregado via AJAX -->
            </div>
            
            <!-- Paginação FIXA na parte inferior -->
            <div class="produto-pagination">
                <button type="button" class="produto-pagination-btn" id="prevPage" 
                        onclick="mudarPagina(<?= $pagina_atual > 1 ? $pagina_atual - 1 : 1 ?>)" 
                        <?= $pagina_atual <= 1 ? 'disabled' : '' ?>>
                    <i class="fas fa-arrow-left"></i> Anterior
                </button>
                
                <span class="produto-pagination-info">
                    Página <?= $pagina_atual ?> de <?= $total_paginas ?>
                </span>
                
                <button type="button" class="produto-pagination-btn" id="nextPage"
                        onclick="mudarPagina(<?= $pagina_atual < $total_paginas ? $pagina_atual + 1 : $total_paginas ?>)" 
                        <?= $pagina_atual >= $total_paginas ? 'disabled' : '' ?>>
                    Próxima <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>


    <!-- Modal Vendedor -->
    <div class="modal-overlay" id="modalVendedor">
        <div class="modal" style="max-width: 700px;">
            <div class="modal-header">
                <h3>Selecionar Vendedor</h3>
                <button class="modal-close" onclick="fecharModalVendedor()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="vendedor-grid">
                    <?php foreach($vendedores as $vend): ?>
                        <div class="vendedor-card" data-vendedor="<?= htmlspecialchars($vend) ?>" onclick="selecionarVendedor(this)">
                            <div class="vendedor-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="vendedor-name"><?= htmlspecialchars($vend) ?></div>
                            <?php if ($vend != $usuario): ?>
                                <div class="vendedor-actions">
                                    <a href="caixa.php?remover_vendedor=<?= urlencode($vend) ?>" class="btn-delete" title="Remover" onclick="return confirm('Tem certeza que deseja remover este vendedor?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <form method="post" class="add-vendedor">
                    <input type="text" name="novo_vendedor" placeholder="Nome do novo vendedor" required>
                    <button type="submit" name="adicionar_vendedor" class="btn-action primary">
                        <i class="fas fa-plus"></i> Adicionar
                    </button>
                </form>
                
                <button class="btn-confirmar" onclick="confirmarVendedor()">
                    <i class="fas fa-check"></i> Confirmar Vendedor
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Total de Vendas -->
    <div class="modal-overlay" id="modalTotalVendas">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Total de Vendas - <?= date('d/m/Y') ?></h3>
                <button class="modal-close" onclick="fecharModalTotalVendas()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="resumo-caixa" style="animation: fadeIn 0.5s ease;">
                    <?php if ($caixa_aberto): ?>
                        <div class="resumo-item">
                            <span><i class="fas fa-money-bill-wave"></i> Dinheiro:</span>
                            <span>R$ <?= number_format($info_caixa['total_dinheiro'], 2, ',', '.') ?></span>
                        </div>
                        <div class="resumo-item">
                            <span><i class="fas fa-credit-card"></i> Crédito:</span>
                            <span>R$ <?= number_format($info_caixa['total_credito'], 2, ',', '.') ?></span>
                        </div>
                        <div class="resumo-item">
                            <span><i class="fas fa-credit-card"></i> Débito:</span>
                            <span>R$ <?= number_format($info_caixa['total_debito'], 2, ',', '.') ?></span>
                        </div>
                        <div class="resumo-item">
                            <span><i class="fas fa-qrcode"></i> Pix:</span>
                            <span>R$ <?= number_format($info_caixa['total_pix'], 2, ',', '.') ?></span>
                        </div>
                        <div class="resumo-item" style="border-top: 1px solid #ccc; font-weight: bold;">
                            <span>Total Geral:</span>
                            <span style="color: var(--success);">R$ <?= number_format($info_caixa['total_geral'], 2, ',', '.') ?></span>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: var(--danger);">
                            <i class="fas fa-lock"></i> Caixa fechado - Nenhum dado disponível
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Sangria -->
<div class="modal-overlay" id="modalSangria">
    <div class="modal" style="max-width: 700px;">
        <div class="modal-header">
            <h3>Histórico de Sangrias</h3>
            <button class="modal-close" onclick="fecharModalSangria()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="vendas-anteriores-container">
                <table class="vendas-anteriores-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Responsável</th>
                            <th>Motivo</th>
                            <th>Valor</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="lista-sangrias">
                        <!-- As sangrias serão carregadas aqui via AJAX -->
                    </tbody>
                </table>
            </div>
            
            <button type="button" class="btn-confirmar" onclick="abrirModalCadastroSangria()" style="margin-top: 1rem;">
                <i class="fas fa-plus-circle"></i> Cadastrar Nova Sangria
            </button>
        </div>
    </div>
</div>

<!-- Modal Cadastro Sangria -->
<div class="modal-overlay" id="modalCadastroSangria">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Cadastrar Sangria</h3>
            <button class="modal-close" onclick="fecharModalCadastroSangria()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="formSangria" method="post" action="processa_sangria.php">
                <div class="resumo-caixa">
                    <div class="resumo-item">
                        <label for="responsavel">Responsável:</label>
                        <input type="text" id="responsavel" name="responsavel" class="desconto-input" value="<?= htmlspecialchars($usuario) ?>" required>
                    </div>
                    <div class="resumo-item">
                        <label for="motivo">Motivo:</label>
                        <input type="text" id="motivo" name="motivo" class="desconto-input" placeholder="Ex: Vale transporte, Almoço, etc" required>
                    </div>
                    <div class="resumo-item">
                        <label for="valor">Valor:</label>
                        <input type="number" id="valor" name="valor" class="desconto-input" min="0.01" step="0.01" placeholder="0,00" required>
                    </div>
                </div>
                
                <button type="submit" class="btn-confirmar">
                    <i class="fas fa-save"></i> Salvar Sangria
                </button>
            </form>
        </div>
    </div>
</div>
 

    <!-- Modal Pagamento -->
    <div class="modal-overlay" id="modalPagamento">
        <div class="modal" style="max-width: 800px;">
            <div class="modal-header">
                <h3>Formas de Pagamento</h3>
                <button class="modal-close" onclick="fecharModalPagamento()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="formPagamento" method="post" action="caixa.php?venda=<?= $venda_id ?>">
                    <input type="hidden" name="finalizar" value="1">
                    <input type="hidden" name="vendedor" id="inputVendedor" value="<?= $usuario ?>">
                    
                    <div class="pagamento-container">
                        <div class="pagamento-info">
                            <div class="pagamento-info-item">
                                <span>Subtotal:</span>
                                <strong>R$ <?= number_format($total_venda + $desconto_venda, 2, ',', '.') ?></strong>
                            </div>
                            <?php if ($desconto_venda > 0): ?>
                            <div class="pagamento-info-item">
                                <span>Desconto:</span>
                                <strong style="color: var(--danger);">- R$ <?= number_format($desconto_venda, 2, ',', '.') ?></strong>
                            </div>
                            <?php endif; ?>
                            <div class="pagamento-info-item">
    <span>Total a Pagar:</span>
    <strong style="color: var(--success); font-size: 1.1rem;" id="total-pagar">R$ <?= number_format($total_venda, 2, ',', '.') ?></strong>
</div>
                            <div class="pagamento-info-item">
                                <span>Valor Pago:</span>
                                <strong id="valor-pago">R$ 0,00</strong>
                            </div>
                            <div class="pagamento-info-item">
                                <span>Troco:</span>
                                <strong id="valor-troco" style="color: var(--danger);">R$ 0,00</strong>
                            </div>
                        </div>
                        
                        <div class="pagamento-formas">
                            <div class="forma-pagamento" data-forma="dinheiro" onclick="selecionarFormaPagamento(this)">
                                <div class="forma-icon">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="forma-details">
                                    <div class="forma-name">Dinheiro</div>
                                    <div class="forma-value">
                                        <input type="number" 
                                               name="valor_pago[]" 
                                               class="valor-input" 
                                               value="0.00" 
                                               min="0" 
                                               step="any" 
                                               onchange="calcularTotais()"
                                               onfocus="this.value='';" 
                                               placeholder="Valor">
                                        <input type="hidden" name="forma_pagamento[]" value="DINHEIRO">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="forma-pagamento" data-forma="cartao_credito" onclick="selecionarFormaPagamento(this)">
                                <div class="forma-icon">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                <div class="forma-details">
                                    <div class="forma-name">Cartão de Crédito</div>
                                    <div class="forma-value">
                                        <input type="number" 
                                               name="valor_pago[]" 
                                               class="valor-input" 
                                               value="0.00" 
                                               min="0" 
                                               step="0.01" 
                                               onchange="calcularTotais()"
                                               placeholder="Valor">
                                        <input type="hidden" name="forma_pagamento[]" value="CARTAO_CREDITO">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="forma-pagamento" data-forma="cartao_debito" onclick="selecionarFormaPagamento(this)">
                                <div class="forma-icon">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                <div class="forma-details">
                                    <div class="forma-name">Cartão de Débito</div>
                                    <div class="forma-value">
                                        <input type="number" 
                                               name="valor_pago[]" 
                                               class="valor-input" 
                                               value="0.00" 
                                               min="0" 
                                               step="0.01" 
                                               onchange="calcularTotais()"
                                               placeholder="Valor">
                                        <input type="hidden" name="forma_pagamento[]" value="CARTAO_DEBITO">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="forma-pagamento" data-forma="pix" onclick="selecionarFormaPagamento(this)">
                                <div class="forma-icon">
                                    <i class="fas fa-qrcode"></i>
                                </div>
                                <div class="forma-details">
                                    <div class="forma-name">Pix</div>
                                    <div class="forma-value">
                                        <input type="number" 
                                               name="valor_pago[]" 
                                               class="valor-input" 
                                               value="0.00" 
                                               min="0" 
                                               step="0.01" 
                                               onchange="calcularTotais()"
                                               placeholder="Valor">
                                        <input type="hidden" name="forma_pagamento[]" value="PIX">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 1rem; text-align: center;">
                        <label style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; font-size: 0.9rem;">
                            <input type="checkbox" name="emitir_nfce" value="1" checked>
                            Emitir NFC-e para esta venda
                        </label>
                    </div>
                    
                    <button type="submit" class="btn-confirmar" id="btnConfirmarPagamento" disabled>
                        <i class="fas fa-check-circle"></i> Confirmar Pagamento
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Caixa (Abertura/Fechamento) -->
    <div class="modal-overlay" id="modalCaixa">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h3><?= $caixa_aberto ? 'Fechar Caixa' : 'Abrir Caixa' ?></h3>
                <button class="modal-close" onclick="fecharModalCaixa()">&times;</button>
            </div>
            <div class="modal-body">
                <?php if ($caixa_aberto): ?>
                    <!-- Resumo para fechamento -->
                    <h3 style="margin-bottom: 1rem; font-size: 1.1rem;">Resumo do Caixa</h3>
                    
                    <div class="resumo-caixa">
                        <div class="resumo-item">
                            <span>Data Abertura:</span>
                            <span><?= date('d/m/Y H:i', strtotime($caixa['data_abertura'])) ?></span>
                        </div>
                        <div class="resumo-item">
                            <span>Valor Abertura:</span>
                            <span>R$ <?= number_format($caixa['valor_abertura'], 2, ',', '.') ?></span>
                        </div>
                        <div class="resumo-item">
                            <span>Total em Dinheiro:</span>
                            <span>R$ <?= number_format($info_caixa['total_dinheiro'], 2, ',', '.') ?></span>
                        </div>
                        <div class="resumo-item">
                            <span>Total Cartão Crédito:</span>
                            <span>R$ <?= number_format($info_caixa['total_credito'], 2, ',', '.') ?></span>
                        </div>
                        <div class="resumo-item">
                            <span>Total Cartão Débito:</span>
                            <span>R$ <?= number_format($info_caixa['total_debito'], 2, ',', '.') ?></span>
                        </div>
                        <div class="resumo-item">
                            <span>Total Pix:</span>
                            <span>R$ <?= number_format($info_caixa['total_pix'], 2, ',', '.') ?></span>
                        </div>
                        <div class="resumo-item">
                            <span>Total Vendas:</span>
                            <span><?= $info_caixa['total_vendas'] ?></span>
                        </div>
                        <div class="resumo-item">
                            <span>Total Geral:</span>
                            <span style="color: var(--success);">R$ <?= number_format($info_caixa['total_geral'], 2, ',', '.') ?></span>
                        </div>
                        <div class="resumo-item">
                            <span>Valor Fechamento:</span>
                            <span style="font-weight: bold;">R$ <?= number_format($caixa['valor_abertura'] + $info_caixa['total_geral'], 2, ',', '.') ?></span>
                        </div>
                    </div>
                    
                    <form method="post">
                        <input type="hidden" name="acao_caixa" value="fechar">
                        <button type="submit" class="btn-confirmar">
                            <i class="fas fa-lock"></i> Confirmar Fechamento
                        </button>
                    </form>
                <?php else: ?>
                    <!-- Formulário para abertura -->
                    <h3 style="margin-bottom: 1rem; font-size: 1.1rem;">Informe o valor de abertura do caixa</h3>
                    
                    <form method="post">
                        <input type="hidden" name="acao_caixa" value="abrir">
                        <input type="number" name="valor_abertura" class="desconto-input" min="0" step="0.01" value="0.00" required>
                        
                        <button type="submit" class="btn-confirmar">
                            <i class="fas fa-lock-open"></i> Confirmar Abertura
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Últimas Vendas -->
    <div class="modal-overlay" id="modalUltimasVendas">
        <div class="modal" style="max-width: 900px;">
            <div class="modal-header">
                <h3>Últimas Vendas</h3>
                <button class="modal-close" onclick="fecharModalUltimasVendas()">&times;</button>
            </div>
            <div class="modal-body">
                <table class="vendas-anteriores-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Data/Hora</th>
            <th>Vendedor</th>
            <th>Status</th>
            <th>Valor</th>
            <th>Pagamentos</th>
            <th>Ações</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($ultimas_vendas as $v): ?>
            <tr data-venda-id="<?= $v['id_venda'] ?>">
                <td><?= $v['id_venda'] ?></td>
                <td><?= date("d/m/Y H:i", strtotime($v['data_hora_venda'])) ?></td>
                <td><?= htmlspecialchars($v['usuario_vendedor']) ?></td>
                <td>
                    <span class="venda-status <?= $v['status_venda'] == 'CONCLUIDA' ? 'status-concluida' : 'status-cancelada' ?>">
                        <?= htmlspecialchars($v['status_venda']) ?>
                    </span>
                </td>
                <td>R$ <?= number_format($v['valor_total_venda'], 2, ',', '.') ?></td>
                <td><?= htmlspecialchars($v['pagamentos'] ?: '-') ?></td>
                <td>
                    <div class="venda-actions">
                        <?php if ($v['status_venda'] == 'CONCLUIDA'): ?>
                            <button type="button" class="venda-action-btn primary" 
                                    onclick="reimprimirNFCe(<?= $v['id_venda'] ?>)" 
                                    title="Reimprimir NFC-e">
                                <i class="fas fa-print"></i>
                            </button>
                            <button type="button" class="venda-action-btn danger btn-cancelar" 
                                    onclick="cancelarVendaFinalizada(<?= $v['id_venda'] ?>)" 
                                    title="Cancelar Venda">
                                <i class="fas fa-ban"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Troca de Produto -->
<div class="modal-overlay" id="modalTroca">
    <div class="modal" style="max-width: 800px;">
        <div class="modal-header">
            <h3>Troca de Produto</h3>
            <button class="modal-close" onclick="fecharModalTroca()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="formTroca" method="post">
                <input type="hidden" name="processar_troca" value="1">
                
                <div class="troca-container">
                    <!-- Seção 1: Produto que o cliente está trazendo para troca -->
                    <div class="troca-section">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-arrow-right"></i> Produto para Troca (que o cliente está trazendo)
                        </h4>
                        
                        <div class="produto-search-container">
                            <div class="produto-search-box">
                                <input type="text" 
                                       id="pesquisaProdutoTroca" 
                                       class="produto-search-input" 
                                       placeholder="Pesquisar produto por nome ou código..."
                                       autocomplete="off"
                                       required>
                                <button type="button" class="produto-search-btn" onclick="buscarProdutoTroca()">
                                    <i class="fas fa-search"></i> Buscar
                                </button>
                            </div>
                        </div>
                        
                        <div id="resultadoProdutoTroca" style="margin-top: 1rem;"></div>
                        
                        <input type="hidden" id="produto_troca_id" name="produto_saida_id">
                        <input type="hidden" id="preco_troca" value="0">
                        
                        <div class="troca-quantidade" style="margin-top: 1rem; display: none;" id="quantidadeTrocaContainer">
                            <label for="quantidade_troca">Quantidade:</label>
                            <input type="number" 
                                   id="quantidade_troca" 
                                   name="quantidade_saida" 
                                   min="0.001" 
                                   step="0.001" 
                                   value="1" 
                                   class="desconto-input" 
                                   onchange="calcularDiferencaTroca()"
                                   required>
                        </div>
                    </div>
                    
                    <!-- Seção 2: Produto que o cliente vai levar -->
                    <div class="troca-section" style="margin-top: 2rem;">
                        <h4 style="margin-bottom: 1rem; color: var(--primary);">
                            <i class="fas fa-arrow-left"></i> Produto Novo (que o cliente vai levar)
                        </h4>
                        
                        <div class="produto-search-container">
                            <div class="produto-search-box">
                                <input type="text" 
                                       id="pesquisaProdutoNovo" 
                                       class="produto-search-input" 
                                       placeholder="Pesquisar produto por nome ou código..."
                                       autocomplete="off"
                                       required>
                                <button type="button" class="produto-search-btn" onclick="buscarProdutoNovo()">
                                    <i class="fas fa-search"></i> Buscar
                                </button>
                            </div>
                        </div>
                        
                        <div id="resultadoProdutoNovo" style="margin-top: 1rem;"></div>
                        
                        <input type="hidden" id="produto_novo_id" name="produto_entrada_id">
                        <input type="hidden" id="preco_novo" value="0">
                        
                        <div class="troca-quantidade" style="margin-top: 1rem; display: none;" id="quantidadeNovoContainer">
                            <label for="quantidade_novo">Quantidade:</label>
                            <input type="number" 
                                   id="quantidade_novo" 
                                   name="quantidade_entrada" 
                                   min="0.001" 
                                   step="0.001" 
                                   value="1" 
                                   class="desconto-input" 
                                   onchange="calcularDiferencaTroca()"
                                   required>
                        </div>
                    </div>
                    
                    <!-- Seção 3: Resumo da troca -->
                    <div class="troca-resumo" style="margin-top: 2rem; padding: 1rem; background: #f5f7ff; border-radius: 0.5rem; display: none;" id="resumoTroca">
                        <h4 style="margin-bottom: 1rem; text-align: center;">Resumo da Troca</h4>
                        
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span>Valor do Produto para Troca:</span>
                            <strong id="valor-produto-troca">R$ 0,00</strong>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span>Valor do Produto Novo:</span>
                            <strong id="valor-produto-novo">R$ 0,00</strong>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 1.1rem;">
                            <span>Diferença:</span>
                            <strong id="valor-diferenca" style="color: var(--primary);">R$ 0,00</strong>
                            <input type="hidden" id="input_valor_diferenca" name="valor_diferenca" value="0">
                        </div>
                        
                        <div id="forma-pagamento-troca-container" style="margin-top: 1rem; display: none;">
                            <label for="forma_pagamento_troca">Forma de Pagamento da Diferença:</label>
                            <select id="forma_pagamento_troca" name="forma_pagamento_troca" class="desconto-input" required>
                                <option value="DINHEIRO">Dinheiro</option>
                                <option value="CARTAO_CREDITO">Cartão de Crédito</option>
                                <option value="CARTAO_DEBITO">Cartão de Débito</option>
                                <option value="PIX">Pix</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Botões de ação -->
                    <div style="display: flex; justify-content: space-between; margin-top: 2rem;">
                        <button type="button" class="btn-action secondary" onclick="fecharModalTroca()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        
                        <button type="button" class="btn-action warning" onclick="refazerTroca()">
                            <i class="fas fa-redo"></i> Refazer
                        </button>
                        
                        <button type="submit" class="btn-action primary" id="btnConfirmarTroca" disabled>
                            <i class="fas fa-check"></i> Confirmar Troca
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Modal Desconto -->
    <div class="modal-overlay" id="modalDesconto">
        <div class="modal" style="max-width: 450px;">
            <div class="modal-header">
                <h3>Aplicar Desconto</h3>
                <button class="modal-close" onclick="fecharModalDesconto()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="desconto-container">
                    <form method="post" class="desconto-form">
                        <div class="desconto-options">
                            <div class="desconto-option">
                                <input type="radio" id="tipo_valor" name="tipo_desconto" value="valor" checked>
                                <label for="tipo_valor">Valor Fixo (R$)</label>
                            </div>
                            <div class="desconto-option">
                                <input type="radio" id="tipo_percentual" name="tipo_desconto" value="percentual">
                                <label for="tipo_percentual">Percentual (%)</label>
                            </div>
                        </div>
                        
                        <input type="number" 
                               name="valor_desconto" 
                               class="desconto-input" 
                               min="0" 
                               step="1" 
                               placeholder="Valor do desconto" 
                               required>
                        
                        <button type="submit" name="aplicar_desconto" class="btn-confirmar">
                            <i class="fas fa-tag"></i> Aplicar Desconto
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Relatório Vendedores -->
<div class="modal-overlay" id="modalRelatorioVendedores">
    <div class="modal" style="max-width: 800px;">
        <div class="modal-header">
            <h3>Relatório de Vendedores</h3>
            <button class="modal-close" onclick="fecharModalRelatorioVendedores()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="relatorio-container">
                <form id="formRelatorioVendedores" class="relatorio-form">
                    <div class="date-inputs">
                        <input type="date" name="data_inicio" id="data_inicio" class="date-input" value="<?= date('Y-m-d') ?>" required>
                        <input type="date" name="data_fim" id="data_fim" class="date-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <button type="button" class="btn-confirmar" onclick="gerarRelatorioVendedores()">
                        <i class="fas fa-chart-bar"></i> Gerar Relatório
                    </button>
                </form>
                
                <div id="relatorio-resultado" style="margin-top: 1rem;">
                    <!-- Resultados serão carregados aqui via AJAX -->
                    <?php if (isset($relatorio_vendedores)): ?>
                        <table class="relatorio-table">
    <thead>
        <tr>
            <th>Vendedor</th>
            <th>Total Vendas</th>
            <th>Total Itens</th>
            <th>Valor Total</th>
            <th>Média por Venda</th>
            <th>aproveitamento</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($relatorio_vendedores as $vendedor): ?>
            <tr>
                <td><?= htmlspecialchars($vendedor['usuario_vendedor']) ?></td>
                <td><?= $vendedor['total_vendas'] ?></td>
                <td><?= $vendedor['total_itens'] ?></td>
                <td>R$ <?= number_format($vendedor['valor_total'], 2, ',', '.') ?></td>
                <td>R$ <?= number_format($vendedor['valor_total'] / $vendedor['total_vendas'], 2, ',', '.') ?></td>
                <td><?= number_format($vendedor['total_itens'] / $vendedor['total_vendas'], 2, ',', '.') ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

    <!-- NO FINAL DO BODY, ANTES DOS SCRIPTS -->
<div class="overlay" id="loadingOverlay"></div>
<div class="spinner" id="loadingSpinner"></div>

<div class="loading-message" id="loadingMessage">
    <i class="fas fa-spinner fa-spin"></i> Adicionando produto...
</div>

<div class="loading-message success" id="successMessage" style="background: var(--success);">
    <i class="fas fa-check"></i> Produto adicionado com sucesso!
</div>

<!-- Adicione isso antes do </body> -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
// Variáveis globais
// Variáveis globais
let vendedorSelecionado = "<?= $usuario ?>";
const vendaId = <?= $venda_id ?: 'null' ?>;
let totalVenda = <?= $total_venda ?>;
let descontoVenda = <?= $desconto_venda ?>;
let selectedVendedor = null;
let selectedFormaPagamento = null;
let filtroTimeout = null;
let isAddingProduct = false;

// Controle de paginação
let currentPage = 1;
let totalPages = <?= $total_paginas ?>;
let currentSearchTerm = '';

// Funções para abrir/fechar modais
function abrirModalProdutos() {
    const modal = document.getElementById('modalProdutos');
    modal.style.display = 'flex';
    setTimeout(() => {
        modal.classList.add('active');
    }, 10);
    
    document.getElementById('pesquisaProduto').value = '';
    currentSearchTerm = '';
    carregarProdutos(1);
}

function fecharModalProdutos() {
    const modal = document.getElementById('modalProdutos');
    modal.classList.remove('active');
    
    // Adiciona animação de fade out
    modal.style.transition = 'opacity 0.3s ease';
    modal.style.opacity = '0';
    
    setTimeout(() => {
        modal.style.display = 'none';
        modal.style.opacity = '1';
        modal.style.transition = '';
    }, 300);
}

function abrirModalVendedor() {
    if (!vendaId || totalVenda <= 0) {
        alert('Adicione produtos à venda antes de finalizar');
        return;
    }
    document.getElementById('modalVendedor').classList.add('active');
}

function fecharModalVendedor() {
    document.getElementById('modalVendedor').classList.remove('active');
}

function abrirModalPagamento() {
    document.getElementById('modalPagamento').classList.add('active');
    calcularTotais();
}

function fecharModalPagamento() {
    document.getElementById('modalPagamento').classList.remove('active');
}

function abrirModalCaixa() {
    document.getElementById('modalCaixa').classList.add('active');
}

function fecharModalCaixa() {
    document.getElementById('modalCaixa').classList.remove('active');
}

function abrirModalUltimasVendas() {
    document.getElementById('modalUltimasVendas').classList.add('active');
}

function fecharModalUltimasVendas() {
    document.getElementById('modalUltimasVendas').classList.remove('active');
}

function abrirModalDesconto() {
    document.getElementById('modalDesconto').classList.add('active');
}

function fecharModalDesconto() {
    document.getElementById('modalDesconto').classList.remove('active');
}

function abrirModalRelatorioVendedores() {
    document.getElementById('modalRelatorioVendedores').classList.add('active');
}

function fecharModalRelatorioVendedores() {
    document.getElementById('modalRelatorioVendedores').classList.remove('active');
}

function abrirModalTotalVendas() {
    document.getElementById('modalTotalVendas').classList.add('active');
}

function fecharModalTotalVendas() {
    document.getElementById('modalTotalVendas').classList.remove('active');
}

function abrirModalSangria() {
    if (!<?= $caixa_aberto ? 'true' : 'false' ?>) {
        alert('O caixa precisa estar aberto para registrar sangrias');
        return;
    }
    
    showLoading('Carregando sangrias...');
    fetch('buscar_sangrias.php')
        .then(response => response.text())
        .then(data => {
            document.getElementById('lista-sangrias').innerHTML = data;
            document.getElementById('modalSangria').classList.add('active');
        })
        .catch(error => console.error('Erro:', error))
        .finally(() => hideLoading());
}

function fecharModalSangria() {
    document.getElementById('modalSangria').classList.remove('active');
}

function abrirModalCadastroSangria() {
    document.getElementById('modalCadastroSangria').classList.add('active');
}

function fecharModalCadastroSangria() {
    document.getElementById('modalCadastroSangria').classList.remove('active');
}

// Funções de produtos
function carregarProdutos(pagina, termo = '') {
    showLoading('Carregando produtos...');
    currentPage = pagina;
    currentSearchTerm = termo;
    
    fetch(`buscar_produtos.php?pagina=${pagina}&cnpj_loja=<?= $cnpj_loja ?>&termo=${encodeURIComponent(termo)}`)
        .then(response => {
            if (!response.ok) throw new Error('Erro na rede');
            return response.text();
        })
        .then(html => {
            document.getElementById('produtoGridContainer').innerHTML = html;
            atualizarPaginacao(pagina);
        })
        .catch(error => {
            console.error('Erro:', error);
            mostrarErroProdutos('Erro ao carregar produtos. Por favor, tente novamente.');
        })
        .finally(() => hideLoading());
}

function mostrarErroProdutos(mensagem) {
    document.getElementById('produtoGridContainer').innerHTML = `
        <div class="msg erro" style="margin: 1rem;">
            <i class="fas fa-exclamation-triangle"></i> ${mensagem}
        </div>`;
}

function filtrarProdutos() {
    clearTimeout(filtroTimeout);
    
    filtroTimeout = setTimeout(() => {
        const termo = document.getElementById('pesquisaProduto').value.trim();
        carregarProdutos(1, termo);
    }, 500);
}

function mudarPagina(pagina) {
    if (pagina < 1) pagina = 1;
    if (pagina > totalPages) pagina = totalPages;
    
    carregarProdutos(pagina, currentSearchTerm);
}

function atualizarPaginacao(pagina) {
    const paginationInfo = document.querySelector('.produto-pagination-info');
    const prevButton = document.getElementById('prevPage');
    const nextButton = document.getElementById('nextPage');
    
    if (paginationInfo) {
        paginationInfo.textContent = `Página ${pagina} de ${totalPages}`;
    }
    
    if (prevButton) {
        prevButton.disabled = pagina <= 1;
        prevButton.onclick = () => mudarPagina(pagina - 1);
    }
    
    if (nextButton) {
        nextButton.disabled = pagina >= totalPages;
        nextButton.onclick = () => mudarPagina(pagina + 1);
    }
}

function adicionarProduto(idProduto) {
    if (isAddingProduct) return;
    isAddingProduct = true;
    
    const quantidadeInput = document.getElementById(`qtd-${idProduto}`);
    const quantidade = parseFloat(quantidadeInput.value) || 1;
    
    if (quantidade <= 0) {
        Swal.fire('Erro', 'Informe uma quantidade válida', 'error');
        isAddingProduct = false;
        return;
    }
    
    // --- NOVO: VERIFICA ESTOQUE ANTES ---
    showLoading('Verificando estoque...');
    
    fetch(`verificar_estoque.php?id_produto=${idProduto}&quantidade=${quantidade}`)
        .then(response => response.json())
        .then(data => {
            if (data.estoque_disponivel >= quantidade) {
                // Se tem estoque, chama a função que adiciona de fato
                adicionarProdutoAposVerificacao(idProduto, quantidade);
            } else {
                // --- NOVO: MOSTRA ERRO DETALHADO ---
                Swal.fire({
                    title: 'Estoque Insuficiente',
                    html: `Produto: <b>${data.nome_produto}</b><br>
                           Em estoque: <b>${data.estoque_disponivel}</b><br>
                           Você tentou: <b>${quantidade}</b>`,
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            Swal.fire('Erro', 'Não foi possível verificar o estoque', 'error');
        })
        .finally(() => {
            isAddingProduct = false;
            hideLoading();
        });
}

// --- NOVA FUNÇÃO SEPARADA PARA ADICIONAR APÓS VERIFICAÇÃO ---
function adicionarProdutoAposVerificacao(idProduto, quantidade) {
    showLoading('Adicionando produto...');
    
    const formData = new FormData();
    formData.append('id_produto', idProduto);
    formData.append('quantidade', quantidade);
    formData.append('add_produto_modal', '1');
    formData.append('venda', vendaId);
    
    fetch(`adicionar_produto_ajax.php?venda=${vendaId}`, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Mostra feedback visual
            showSuccessMessage(`${data.produto_adicionado.nome} adicionado!`);
            
            // Fecha o modal de produtos
            fecharModalProdutos();
            
            // Atualiza a tabela de itens se houver dados
            if (data.itens_venda) {
                atualizarTabelaItens(data.itens_venda);
            }
            
            // Foca no campo de código para próxima leitura
            setTimeout(() => {
                document.getElementById('codigoInput')?.focus();
            }, 500);
            
            // Mantém o feedback por 1.5 segundos
            setTimeout(hideLoading, 1500);
        } else {
            throw new Error(data.message);
        }
    })
    .catch(error => {
        hideLoading();
        Swal.fire('Erro', error.message, 'error');
    })
    .finally(() => {
        isAddingProduct = false;
    });
}

function atualizarTabelaItens(itens) {
    const tbody = document.querySelector('.itens-table tbody');
    const tfoot = document.querySelector('.itens-table tfoot');

    // Limpa o conteúdo atual da tabela
    tbody.innerHTML = '';

    if (itens.length === 0) {
        // Exibe mensagem quando não há itens
        tbody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 5rem 1rem; color: var(--gray-700);">
                    <i class="fas fa-shopping-cart" style="font-size: 2rem; margin-bottom: 0.8rem; opacity: 0.3;"></i>
                    <p style="font-size: 0.9rem;">Nenhum produto adicionado à venda</p>
                </td>
            </tr>
        `;
    } else {
        // Preenche a tabela com os itens da venda
        itens.forEach(item => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <div class="produto-img-container">
                        ${item.foto_produto ? 
                            `<img src="uploads/imagens/${item.foto_produto}" class="produto-img" alt="${item.nome_produto}">` : 
                            `<i class="fas fa-image" style="color: #d1d5db; font-size: 2rem;"></i>`
                        }
                    </div>
                </td>
                <td>${item.sequencial_item}</td>
                <td>${item.referencia_interna}</td>
                <td>${item.nome_produto}</td>
                <td>${parseFloat(item.quantidade).toFixed(3).replace('.', ',')}</td>
                <td>${item.unidade_medida}</td>
                <td>R$ ${parseFloat(item.preco_unitario_praticado).toFixed(2).replace('.', ',')}</td>
                <td>R$ ${parseFloat(item.valor_total_item).toFixed(2).replace('.', ',')}</td>
                <td>
                    <button type="button"
                        class="btn-remove"
                        title="Remover"
                        data-item-id="${item.id_item_venda}">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(row);
        });
    }

    // Calcula totais
    const subtotal = itens.reduce((sum, item) => sum + parseFloat(item.valor_total_item), 0);
    const desconto = parseFloat(document.querySelector('input[name="valor_desconto"]')?.value) || 0;
    const total = subtotal - desconto;

    // Atualiza o rodapé da tabela com os totais
    tfoot.innerHTML = `
        <tr>
            <td colspan="6" style="text-align: right; font-size: 1rem;">Subtotal:</td>
            <td colspan="3">R$ ${subtotal.toFixed(2).replace('.', ',')}</td>
        </tr>
        ${desconto > 0 ? `
        <tr>
            <td colspan="6" style="text-align: right; font-size: 1rem;">Desconto:</td>
            <td colspan="3" style="color: var(--danger);">- R$ ${desconto.toFixed(2).replace('.', ',')}</td>
        </tr>
        ` : ''}
        <tr>
            <td colspan="6" style="text-align: right; font-size: 1rem;">Total:</td>
            <td colspan="3" class="total-amount">R$ ${total.toFixed(2).replace('.', ',')}</td>
        </tr>
    `;

    // Atualiza os botões de ação
    const btnFinalizar = document.getElementById('btnFinalizar');
    const btnCancelar = document.getElementById('btnCancelar');
    const btnDesconto = document.querySelector('[onclick="abrirModalDesconto()"]');

    if (btnFinalizar && btnCancelar && btnDesconto) {
        const hasItems = itens.length > 0;
        // ATENÇÃO: Substitua o valor abaixo pelo correto para sua aplicação.
        // Se você usa PHP para passar o valor de $caixa_aberto, substitua aqui:
        const caixaAberto = true; // ou false

        btnFinalizar.disabled = !hasItems || !caixaAberto;
        btnCancelar.disabled = !hasItems || !caixaAberto;
        btnDesconto.disabled = !hasItems;
    }

    // Atualiza variáveis globais (IMPORTANTE para o modal de pagamento)
    totalVenda = total;
    descontoVenda = desconto;

    // Atualiza o título da página com o total
    document.title = `R$ ${total.toFixed(2)} - PDV KIARA DSOLE`;

    // Atualiza o modal de pagamento se estiver aberto
    if (document.getElementById('modalPagamento').classList.contains('active')) {
        abrirModalPagamento(); // Isso irá recalcular e atualizar os valores
    }

    // Foca no campo de código de barras para próxima leitura
    const codigoInput = document.getElementById('codigoInput');
    if (codigoInput) {
        codigoInput.focus();
    }
}

// Funções de vendedor
function selecionarVendedor(element) {
    if (selectedVendedor) {
        selectedVendedor.classList.remove('selected');
    }
    element.classList.add('selected');
    selectedVendedor = element;
    vendedorSelecionado = element.dataset.vendedor;
}

function confirmarVendedor() {
    if (!vendedorSelecionado) {
        alert('Selecione um vendedor');
        return;
    }
    
    document.getElementById('inputVendedor').value = vendedorSelecionado;
    fecharModalVendedor();
    abrirModalPagamento();
}

function abrirModalPagamento() {
    document.getElementById('modalPagamento').classList.add('active');
    
    // Atualiza os valores exibidos no modal
    document.getElementById('total-pagar').textContent = formatarMoeda(totalVenda);
    document.querySelector('.pagamento-info-item:nth-child(1) strong').textContent = 
        formatarMoeda(totalVenda + descontoVenda);
    
    if (descontoVenda > 0) {
        document.querySelector('.pagamento-info-item:nth-child(2) strong').textContent = 
            '- ' + formatarMoeda(descontoVenda);
    }
    
    calcularTotais();
}

// Funções de pagamento
function selecionarFormaPagamento(element) {
    if (selectedFormaPagamento) {
        selectedFormaPagamento.classList.remove('selected');
    }
    element.classList.add('selected');
    selectedFormaPagamento = element;
    
    const input = element.querySelector('.valor-input');
    if (input) {
        input.focus();
    }
}

function calcularTotais() {
    const inputs = document.querySelectorAll('.valor-input');
    let totalPago = 0;
    
    inputs.forEach(input => {
        totalPago += parseFloat(input.value) || 0;
    });
    
    document.getElementById('valor-pago').textContent = formatarMoeda(totalPago);
    
    const totalComDesconto = totalVenda - descontoVenda;
    const troco = totalPago - totalComDesconto;
    const trocoElement = document.getElementById('valor-troco');
    const btnConfirmar = document.getElementById('btnConfirmarPagamento');
    
    if (troco >= 0) {
        trocoElement.textContent = formatarMoeda(troco);
        trocoElement.style.color = 'var(--success)';
        btnConfirmar.disabled = false;
    } else {
        trocoElement.textContent = formatarMoeda(0);
        trocoElement.style.color = 'var(--danger)';
        btnConfirmar.disabled = true;
    }
}

function formatarMoeda(valor) {
    return 'R$ ' + valor.toFixed(2).replace('.', ',');
}

// Funções auxiliares
function showLoading(message = 'Processando...') {
    const loadingMsg = document.getElementById('loadingMessage');
    loadingMsg.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${message}`;
    loadingMsg.style.display = 'block';
    document.getElementById('loadingOverlay').style.display = 'block';
    document.getElementById('loadingSpinner').style.display = 'block';
}

function showSuccessMessage(message = 'Operação concluída!') {
    const successMsg = document.getElementById('successMessage');
    successMsg.innerHTML = `<i class="fas fa-check"></i> ${message}`;
    successMsg.style.display = 'block';
    
    setTimeout(() => {
        successMsg.style.display = 'none';
    }, 1500);
}

function hideLoading() {
    document.getElementById('loadingOverlay').style.display = 'none';
    document.getElementById('loadingSpinner').style.display = 'none';
    document.getElementById('loadingMessage').style.display = 'none';
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Relógio
    setInterval(updateClock, 1000);
    updateClock();
    
    // Foco no campo de código de barras
    const codigoInput = document.getElementById('codigoInput');
    if (codigoInput) {
        codigoInput.focus();
        
        codigoInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('codigoForm').submit();
            }
        });
    }
    
    // Delegation para elementos dinâmicos
    document.addEventListener('click', function(e) {
        if (e.target.closest('.btn-add-produto')) {
            const btn = e.target.closest('.btn-add-produto');
            const idProduto = btn.getAttribute('onclick').match(/\d+/)[0];
            adicionarProduto(idProduto);
        }
        
        if (e.target.closest('.forma-pagamento')) {
            selecionarFormaPagamento(e.target.closest('.forma-pagamento'));
        }
        
        if (e.target.closest('.vendedor-card')) {
            selecionarVendedor(e.target.closest('.vendedor-card'));
        }
    });
    
    // Fechar mensagens após 5 segundos
    setTimeout(() => {
        const messages = document.querySelectorAll('.msg');
        messages.forEach(msg => {
            msg.style.display = 'none';
        });
    }, 5000);
    
    // Fechar modais ao clicar fora
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });
    
    // Event listeners para pesquisa
    document.getElementById('pesquisaProduto').addEventListener('input', function(e) {
        filtrarProdutos();
    });
    
    document.getElementById('pesquisaProduto').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(filtroTimeout);
            filtrarProdutos();
        }
    });
    
    document.querySelector('.produto-search-btn').addEventListener('click', function(e) {
        e.preventDefault();
        clearTimeout(filtroTimeout);
        filtrarProdutos();
    });
    
    // Campos de valor
    document.querySelectorAll('.valor-input').forEach(input => {
        input.addEventListener('focus', function() {
            if (parseFloat(this.value) === 0) {
                this.value = '';
            }
        });
        
        input.addEventListener('blur', function() {
            if (this.value === '') {
                this.value = '0.00';
            } else {
                let valor = parseFloat(this.value) || 0;
                this.value = valor.toFixed(2);
            }
            calcularTotais();
        });
    });
    
    // Código de barras
    let codigoBarrasTimeout;
    document.getElementById('codigoInput')?.addEventListener('input', function(e) {
        clearTimeout(codigoBarrasTimeout);
        
        if (this.value.length >= 8) { // Mínimo 8 caracteres para código de barras
            codigoBarrasTimeout = setTimeout(() => {
                showLoading('Processando código...');
                document.getElementById('codigoForm').submit();
            }, 300);
        }
    });
});

function updateClock() {
    const now = new Date();
    const dateTimeStr = now.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
    
    document.getElementById('currentDateTime').textContent = dateTimeStr;
}

// Funções para sangria
function imprimirSangria(idSangria) {
    window.open(`imprimir_sangria.php?id=${idSangria}`, '_blank');
}

// Adicionar no final do script
document.getElementById('btnFinalizar')?.addEventListener('click', function() {
    if (totalVenda <= 0) {
        alert('Adicione produtos à venda antes de finalizar');
        return;
    }
    
    abrirModalVendedor();
});

document.getElementById('btnCancelar')?.addEventListener('click', function() {
    if (confirm('Tem certeza que deseja cancelar esta venda?')) {
        document.getElementById('formCancelarVenda').submit();
    }
});

// Atualizar a função confirmarVendedor para enviar o formulário
function confirmarVendedor() {
    if (!vendedorSelecionado) {
        alert('Selecione um vendedor');
        return;
    }
    
    document.getElementById('inputVendedor').value = vendedorSelecionado;
    fecharModalVendedor();
    abrirModalPagamento();
    
    // Quando confirmar o pagamento, enviar o formulário
    document.getElementById('btnConfirmarPagamento').onclick = function() {
        document.getElementById('formFinalizarVenda').submit();
    };
}

// Função para cancelar venda
function cancelarVenda(idVenda) {
    if (confirm('Tem certeza que deseja cancelar esta venda?')) {
        showLoading('Cancelando venda...');
        window.location.href = `caixa.php?cancelar_venda=${idVenda}`;
    }
}

// Função para reimprimir NFC-e
function reimprimirNFCe(idVenda) {
    showLoading('Preparando reimpressão...');
    window.location.href = `caixa.php?reimprimir_nfce=${idVenda}`;
}

function cancelarVendaFinalizada(idVenda) {
    // Fecha qualquer modal aberto primeiro
    fecharModalUltimasVendas();
    
    Swal.fire({
        title: 'Tem certeza?',
        html: `<strong>Cancelar esta venda finalizada?</strong><br><br>
               Esta ação não pode ser desfeita e irá:<br>
               - Restaurar os itens ao estoque<br>
               - Cancelar a NFC-e (se existir)`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sim, cancelar!',
        cancelButtonText: 'Não, manter',
        allowOutsideClick: false,
        customClass: {
            container: 'swal2-container-custom'
        },
        didOpen: () => {
            // Garante posicionamento correto após abrir
            document.querySelector('.swal2-container').style.zIndex = '999999';
        }
    }).then((result) => {
        if (result.isConfirmed) {
            showLoading('Cancelando venda...');
            
            fetch(`cancelar_venda_finalizada.php?id_venda=${idVenda}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            title: 'Cancelada!',
                            text: 'A venda foi cancelada com sucesso.',
                            icon: 'success',
                            timer: 2000,
                            timerProgressBar: true,
                            showConfirmButton: false
                        });
                        
                        // Atualiza a linha da tabela
                        const row = document.querySelector(`tr[data-venda-id="${idVenda}"]`);
                        if (row) {
                            row.cells[3].innerHTML = '<span class="venda-status status-cancelada">CANCELADA</span>';
                            // Desabilita o botão de cancelar
                            const btnCancelar = row.querySelector('.btn-cancelar');
                            if (btnCancelar) {
                                btnCancelar.disabled = true;
                                btnCancelar.style.opacity = '0.5';
                            }
                        }
                    } else {
                        Swal.fire({
                            title: 'Erro!',
                            text: data.message || 'Ocorreu um erro ao cancelar a venda',
                            icon: 'error'
                        });
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    Swal.fire({
                        title: 'Erro!',
                        text: 'Falha na comunicação com o servidor',
                        icon: 'error'
                    });
                })
                .finally(() => {
                    hideLoading();
                });
        }
    });
}

// Corrigindo a função abrirModalTroca
function abrirModalTroca() {
    // Verifica se há venda em aberto
    if (!vendaId) {
        alert('Inicie uma venda antes de realizar trocas');
        return;
    }

    // Verifica se há itens na venda
    fetch(`verificar_itens_venda.php?venda_id=${vendaId}`)
        .then(response => response.json())
        .then(data => {
            if (data.total_itens > 0) {
                // Mostra o modal
                const modal = document.getElementById('modalTroca');
                modal.style.display = 'flex';
                setTimeout(() => {
                    modal.classList.add('active');
                }, 10);
                
                // Foca no campo de pesquisa
                document.getElementById('pesquisaProdutoTroca').focus();
            } else {
                alert('Adicione produtos à venda antes de realizar uma troca');
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Não foi possível verificar os itens da venda');
        });
}

// Corrigindo a função fecharModalTroca
function fecharModalTroca() {
    const modal = document.getElementById('modalTroca');
    modal.classList.remove('active');
    setTimeout(() => {
        modal.style.display = 'none';
    }, 300);
}

function refazerTroca() {
    // Limpa todos os campos e esconde as seções
    document.getElementById('pesquisaProdutoTroca').value = '';
    document.getElementById('pesquisaProdutoNovo').value = '';
    document.getElementById('resultadoProdutoTroca').innerHTML = '';
    document.getElementById('resultadoProdutoNovo').innerHTML = '';
    document.getElementById('quantidadeTrocaContainer').style.display = 'none';
    document.getElementById('quantidadeNovoContainer').style.display = 'none';
    document.getElementById('resumoTroca').style.display = 'none';
    document.getElementById('forma-pagamento-troca-container').style.display = 'none';
    document.getElementById('btnConfirmarTroca').disabled = true;
    
    // Foca no primeiro campo
    document.getElementById('pesquisaProdutoTroca').focus();
}

function buscarProdutoTroca() {
    const termo = document.getElementById('pesquisaProdutoTroca').value.trim();
    if (!termo) return;

    showLoading('Buscando produto...');

    fetch(`buscar_produto_ajax.php?termo=${encodeURIComponent(termo)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.produtos.length > 0) {
                let html = '<ul style="list-style:none;padding:0;">';

                data.produtos.forEach(produto => {
                    html += `
                        <li style="margin-bottom:10px;padding:10px;border-bottom:1px solid #eee;">
                            <b>${produto.nome_produto}</b> 
                            <span style="color:#888;">[${produto.referencia_interna || ''}]</span>
                            <br>
                            <span>Preço: R$ ${parseFloat(produto.preco_venda).toFixed(2).replace('.', ',')}</span>
                            <span> ${produto.unidade_medida} </span>
                            <button type="button" style="margin-left:10px;" class="btn-action primary" onclick="selecionarProdutoTroca(${produto.id_produto}, '${produto.nome_produto.replace(/'/g,"\\'")}', ${produto.preco_venda}, '${produto.unidade_medida}')">
                                Selecionar
                            </button>
                        </li>
                    `;
                });

                html += '</ul>';
                document.getElementById('resultadoProdutoTroca').innerHTML = html;
            } else {
                document.getElementById('resultadoProdutoTroca').innerHTML = `
                    <div class="msg erro">
                        <i class="fas fa-exclamation-triangle"></i> Nenhum produto encontrado
                    </div>
                `;
            }
        })
        .catch(error => {
            document.getElementById('resultadoProdutoTroca').innerHTML = `
                <div class="msg erro">
                    <i class="fas fa-exclamation-triangle"></i> Erro ao buscar produtos
                </div>
            `;
        })
        .finally(() => hideLoading());
}

function buscarProdutoNovo() {
    const termo = document.getElementById('pesquisaProdutoNovo').value.trim();
    if (!termo) return;

    showLoading('Buscando produto...');

    fetch(`buscar_produto_ajax.php?termo=${encodeURIComponent(termo)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.produtos.length > 0) {
                let html = '<ul style="list-style:none;padding:0;">';

                data.produtos.forEach(produto => {
                    html += `
                        <li style="margin-bottom:10px;padding:10px;border-bottom:1px solid #eee;">
                            <b>${produto.nome_produto}</b> 
                            <span style="color:#888;">[${produto.referencia_interna || ''}]</span>
                            <br>
                            <span>Preço: R$ ${parseFloat(produto.preco_venda).toFixed(2).replace('.', ',')}</span>
                            <span> ${produto.unidade_medida} </span>
                            <button type="button" style="margin-left:10px;" class="btn-action primary" onclick="selecionarProdutoNovo(${produto.id_produto}, '${produto.nome_produto.replace(/'/g,"\\'")}', ${produto.preco_venda}, '${produto.unidade_medida}')">
                                Selecionar
                            </button>
                        </li>
                    `;
                });

                html += '</ul>';
                document.getElementById('resultadoProdutoNovo').innerHTML = html;
            } else {
                document.getElementById('resultadoProdutoNovo').innerHTML = `
                    <div class="msg erro">
                        <i class="fas fa-exclamation-triangle"></i> Nenhum produto encontrado
                    </div>
                `;
            }
        })
        .catch(error => {
            document.getElementById('resultadoProdutoNovo').innerHTML = `
                <div class="msg erro">
                    <i class="fas fa-exclamation-triangle"></i> Erro ao buscar produtos
                </div>
            `;
        })
        .finally(() => hideLoading());
}

function selecionarProdutoTroca(id, nome, preco, unidade) {
    document.getElementById('produto_troca_id').value = id;
    document.getElementById('preco_troca').value = preco;
    
    document.getElementById('resultadoProdutoTroca').innerHTML = `
        <div class="produto-selecionado" style="padding: 1rem; background: #f0f4ff; border-radius: 0.5rem;">
            <h5 style="margin-bottom: 0.5rem; color: var(--primary);">Produto para Troca Selecionado:</h5>
            <p><strong>${nome}</strong> - R$ ${preco.toFixed(2).replace('.', ',')} / ${unidade}</p>
        </div>
    `;
    
    document.getElementById('quantidadeTrocaContainer').style.display = 'block';
    verificarTrocaCompleta();
}

function selecionarProdutoNovo(id, nome, preco, unidade) {
    document.getElementById('produto_novo_id').value = id;
    document.getElementById('preco_novo').value = preco;
    
    document.getElementById('resultadoProdutoNovo').innerHTML = `
        <div class="produto-selecionado" style="padding: 1rem; background: #f0f4ff; border-radius: 0.5rem;">
            <h5 style="margin-bottom: 0.5rem; color: var(--primary);">Produto Novo Selecionado:</h5>
            <p><strong>${nome}</strong> - R$ ${preco.toFixed(2).replace('.', ',')} / ${unidade}</p>
        </div>
    `;
    
    document.getElementById('quantidadeNovoContainer').style.display = 'block';
    verificarTrocaCompleta();
}

function verificarTrocaCompleta() {
    const produtoTrocaId = document.getElementById('produto_troca_id').value;
    const produtoNovoId = document.getElementById('produto_novo_id').value;
    
    if (produtoTrocaId && produtoNovoId) {
        document.getElementById('resumoTroca').style.display = 'block';
        calcularDiferencaTroca();
    }
}

function calcularDiferencaTroca() {
    const precoTroca = parseFloat(document.getElementById('preco_troca').value) || 0;
    const precoNovo = parseFloat(document.getElementById('preco_novo').value) || 0;
    const qtdTroca = parseFloat(document.getElementById('quantidade_troca').value) || 1;
    const qtdNovo = parseFloat(document.getElementById('quantidade_novo').value) || 1;
    
    const valorTroca = precoTroca * qtdTroca;
    const valorNovo = precoNovo * qtdNovo;
    const diferenca = valorNovo - valorTroca;
    
    document.getElementById('valor-produto-troca').textContent = `R$ ${valorTroca.toFixed(2).replace('.', ',')}`;
    document.getElementById('valor-produto-novo').textContent = `R$ ${valorNovo.toFixed(2).replace('.', ',')}`;
    document.getElementById('valor-diferenca').textContent = `R$ ${Math.abs(diferenca).toFixed(2).replace('.', ',')}`;
    document.getElementById('input_valor_diferenca').value = diferenca;
    
    // Atualiza cor conforme o valor
    const diffElement = document.getElementById('valor-diferenca');
    if (diferenca > 0) {
        diffElement.style.color = 'var(--danger)';
        diffElement.textContent = `+ R$ ${diferenca.toFixed(2).replace('.', ',')}`;
        document.getElementById('forma-pagamento-troca-container').style.display = 'block';
    } else if (diferenca < 0) {
        diffElement.style.color = 'var(--success)';
        diffElement.textContent = `- R$ ${Math.abs(diferenca).toFixed(2).replace('.', ',')}`;
        document.getElementById('forma-pagamento-troca-container').style.display = 'none';
    } else {
        diffElement.style.color = 'var(--primary)';
        diffElement.textContent = `R$ 0,00`;
        document.getElementById('forma-pagamento-troca-container').style.display = 'none';
    }
    
    document.getElementById('btnConfirmarTroca').disabled = false;
}
function gerarRelatorioVendedores() {
    const dataInicio = document.getElementById('data_inicio').value;
    const dataFim = document.getElementById('data_fim').value;
    
    if (!dataInicio || !dataFim) {
        alert('Selecione ambas as datas');
        return;
    }
    
    if (new Date(dataFim) < new Date(dataInicio)) {
        alert('Data final não pode ser anterior à data inicial');
        return;
    }
    
    showLoading('Gerando relatório...');
    
    const formData = new FormData();
    formData.append('data_inicio', dataInicio);
    formData.append('data_fim', dataFim);
    
    fetch('gerar_relatorio_vendedores.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('relatorio-resultado').innerHTML = data.html;
            
            // Atualiza o título do modal com o período
            const modalHeader = document.querySelector('#modalRelatorioVendedores .modal-header h3');
            if (modalHeader) {
                modalHeader.textContent = `Relatório de Vendedores (${data.periodo})`;
            }
            
            showSuccessMessage('Relatório gerado com sucesso!');
        } else {
            document.getElementById('relatorio-resultado').innerHTML = 
                `<div class="msg erro"><i class="fas fa-exclamation-triangle"></i> ${data.message || 'Erro ao gerar relatório'}</div>`;
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        document.getElementById('relatorio-resultado').innerHTML = 
            `<div class="msg erro"><i class="fas fa-exclamation-triangle"></i> Erro ao conectar com o servidor</div>`;
    })
    .finally(() => {
        hideLoading();
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Verifica se há um botão de nova venda
    const btnNovaVenda = document.querySelector('[name="nova_venda"]');
    if (btnNovaVenda) {
        btnNovaVenda.addEventListener('click', function(e) {
            if (!<?= $caixa_aberto ? 'true' : 'false' ?>) {
                e.preventDefault();
                alert('O caixa precisa estar aberto para iniciar uma nova venda');
                abrirModalCaixa();
            }
        });
    }
});

// Delegation para botões de ação na tabela de vendas
document.addEventListener('click', function(e) {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    
    const action = btn.dataset.action;
    const idVenda = btn.dataset.id;
    
    switch(action) {
        case 'reimprimir':
            showLoading('Preparando reimpressão...');
            window.location.href = `caixa.php?reimprimir_nfce=${idVenda}`;
            break;
            
        case 'cancelar':
            if (confirm('Tem certeza que deseja cancelar esta venda?')) {
                showLoading('Cancelando venda...');
                window.location.href = `caixa.php?cancelar_venda=${idVenda}`;
            }
            break;
    }
});

// Remoção animada de item - SweetAlert2 + AJAX
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-remove[data-item-id]');
    if (!btn) return;
    if (btn.disabled) return;

    const itemId = btn.getAttribute('data-item-id');
    const vendaId = <?= $venda_id ?: 'null' ?>;

    Swal.fire({
        title: 'Remover item?',
        html: '<b>Certeza que deseja remover este item?</b><br>Esta ação não pode ser desfeita! 😱',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sim, remover!',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('remover_item_ajax.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `item_id=${itemId}&venda_id=${vendaId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'Removido!',
                        text: 'O item foi removido da venda!',
                        icon: 'success',
                        timer: 1200,
                        showConfirmButton: false
                    });
                    // Atualiza a tela recarregando a página (volta ao caixa.php)
                    setTimeout(() => {
                        window.location.href = "caixa.php?venda=" + vendaId;
                    }, 1200);
                } else {
                    Swal.fire('Erro!', data.message || 'Erro ao remover item!', 'error');
                }
            })
            .catch(() => {
                Swal.fire('Erro!', 'Houve um problema ao remover o item.', 'error');
            });
        }
    });
});
</script>
</body>
</html>
buscar_produto.php