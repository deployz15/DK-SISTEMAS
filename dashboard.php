<?php
session_start();
session_regenerate_id(true);

if (!isset($_SESSION['usuario_logado']) || !$_SESSION['usuario_logado']) {
    header('Location: login.php');
    exit;
}

require_once 'conexao.php';

// Dados da sessão
$usuario = $_SESSION['nome_usuario_logado'] ?? '';
$cnpj_loja = $_SESSION['cnpj_loja_logada'] ?? '';

// Buscar o caminho da logo da loja
$stmt = $pdo->prepare("SELECT logo_path FROM lojas WHERE cnpj = :cnpj");
$stmt->bindParam(':cnpj', $cnpj_loja);
$stmt->execute();
$logo_loja = $stmt->fetchColumn();

// Buscar dados da loja
$stmt = $pdo->prepare("SELECT id_loja, nome_fantasia, razao_social FROM lojas WHERE cnpj = :cnpj");
$stmt->bindParam(':cnpj', $cnpj_loja, PDO::PARAM_STR);
$stmt->execute();
$loja = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loja) {
    die("Erro: Loja não encontrada para o CNPJ informado.");
}

$id_loja = $loja['id_loja'];
$nome_loja = $loja['nome_fantasia'] ?? $loja['razao_social'] ?? '';

// Processar filtro de relatório
$relatorio_data = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gerar_relatorio'])) {
    $data_inicio = $_POST['data_inicio'] ?? date('Y-m-01');
    $data_fim = $_POST['data_fim'] ?? date('Y-m-t');
    
    if (strtotime($data_fim) < strtotime($data_inicio)) {
        $erro_relatorio = "A data final não pode ser anterior à data inicial";
    } else {
        // Buscar vendas no período
        $stmt = $pdo->prepare("
            SELECT v.*, c.nome_razao_social, 
                   GROUP_CONCAT(DISTINCT p.forma_pagamento SEPARATOR ', ') as formas_pagamento
            FROM vendas v
            LEFT JOIN clientes c ON v.id_cliente = c.id_cliente
            LEFT JOIN pagamentos_venda p ON v.id_venda = p.id_venda
            WHERE v.cnpj_loja = :cnpj_loja
              AND DATE(v.data_hora_venda) BETWEEN :data_inicio AND :data_fim
              AND v.status_venda = 'CONCLUIDA'
            GROUP BY v.id_venda
            ORDER BY v.data_hora_venda DESC
        ");
        $stmt->execute([
            'cnpj_loja' => $cnpj_loja,
            'data_inicio' => $data_inicio,
            'data_fim' => $data_fim
        ]);
        $relatorio_data['vendas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calcular totais
        $relatorio_data['total_vendas'] = 0;
        $relatorio_data['total_pedidos'] = count($relatorio_data['vendas']);
        foreach ($relatorio_data['vendas'] as $venda) {
            $relatorio_data['total_vendas'] += $venda['valor_total_venda'];
        }
        
        // Buscar produtos mais vendidos
        $stmt = $pdo->prepare("
            SELECT p.nome_produto, p.referencia_interna, SUM(iv.quantidade) as total_vendido, 
                   SUM(iv.valor_total_item) as total_faturado
            FROM itens_venda iv
            JOIN vendas v ON iv.id_venda = v.id_venda
            JOIN produtos p ON iv.id_produto = p.id_produto
            WHERE v.cnpj_loja = :cnpj_loja
              AND DATE(v.data_hora_venda) BETWEEN :data_inicio AND :data_fim
              AND v.status_venda = 'CONCLUIDA'
            GROUP BY iv.id_produto
            ORDER BY total_vendido DESC
            LIMIT 5
        ");
        $stmt->execute([
            'cnpj_loja' => $cnpj_loja,
            'data_inicio' => $data_inicio,
            'data_fim' => $data_fim
        ]);
        $relatorio_data['produtos_mais_vendidos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Formatar datas para exibição
        $relatorio_data['data_inicio'] = date('d/m/Y', strtotime($data_inicio));
        $relatorio_data['data_fim'] = date('d/m/Y', strtotime($data_fim));
    }
}

// Funções auxiliares
function removerAcentos($string) {
    return preg_replace([
        "/(á|à|ã|â|ä)/", "/(Á|À|Ã|Â|Ä)/",
        "/(é|è|ê|ë)/", "/(É|È|Ê|Ë)/",
        "/(í|ì|î|ï)/", "/(Í|Ì|Î|Ï)/",
        "/(ó|ò|õ|ô|ö)/", "/(Ó|Ò|Õ|Ô|Ö)/",
        "/(ú|ù|û|ü)/", "/(Ú|Ù|Û|Ü)/",
        "/(ñ)/", "/(Ñ)/", "/(ç)/", "/(Ç)/"
    ], explode(" ","a A e E i I o O u U n N c C"), $string);
}

function calcularDigitoEAN13($codigo) {
    if (strlen($codigo) != 12) return 0;
    
    $soma = 0;
    for ($i = 0; $i < 12; $i++) {
        $digito = (int)$codigo[$i];
        $soma += ($i % 2 === 0) ? $digito : $digito * 3;
    }
    $resto = $soma % 10;
    return $resto === 0 ? 0 : 10 - $resto;
}

// Gerenciamento de Produtos
$erro_produto = '';
$produto_editar = null;

// Processar cadastro de produto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastro_produto'])) {
    $dados_produto = [
        'id_loja' => $id_loja,
        'nome_produto' => trim($_POST['nome'] ?? ''),
        'descricao' => trim($_POST['descricao'] ?? ''),
        'codigo_barras_ean' => trim($_POST['codigo_barras'] ?? ''),
        'referencia_interna' => trim($_POST['referencia'] ?? ''),
        'unidade_medida' => trim($_POST['unidade_medida'] ?? 'UN'),
        'preco_custo' => 0.00,
        'preco_venda' => floatval(str_replace(',', '.', $_POST['preco'] ?? 0)),
        'estoque_atual' => floatval(str_replace(',', '.', $_POST['estoque'] ?? 0)),
        'ncm' => trim($_POST['ncm'] ?? '01012100'),
        'ibpt' => floatval(str_replace(',', '.', $_POST['ibpt'] ?? 4.20)),
        'origem_mercadoria' => '0',
        'cfop' => '5102',
        'icms_aliquota' => 18.00,
        'pis_aliquota' => 0.65,
        'cofins_aliquota' => 3.00,
        'ativo' => 1
    ];

    // Validação
    if (empty($dados_produto['referencia_interna'])) {
        $erro_produto = "O código de referência é obrigatório!";
    } elseif (empty($dados_produto['nome_produto'])) {
        $erro_produto = "O nome do produto é obrigatório!";
    } elseif ($dados_produto['preco_venda'] <= 0) {
        $erro_produto = "O preço de venda deve ser maior que zero!";
    } elseif ($dados_produto['estoque_atual'] < 0) {
        $erro_produto = "O estoque não pode ser negativo!";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Upload da foto
            $foto_nome = '';
            if (isset($_FILES['foto_produto']) && $_FILES['foto_produto']['error'] == UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_type = $_FILES['foto_produto']['type'];
                
                if (in_array($file_type, $allowed_types) && $_FILES['foto_produto']['size'] <= 2 * 1024 * 1024) {
                    $ext = pathinfo($_FILES['foto_produto']['name'], PATHINFO_EXTENSION);
                    $foto_nome = uniqid() . '.' . strtolower($ext);
                    $upload_dir = __DIR__ . '/uploads/imagens/';
                    
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    if (move_uploaded_file($_FILES['foto_produto']['tmp_name'], $upload_dir . $foto_nome)) {
                        $dados_produto['foto_produto'] = $foto_nome;
                    }
                }
            }
            
            // Inserir produto
            $campos = implode(', ', array_keys($dados_produto));
            $placeholders = ':' . implode(', :', array_keys($dados_produto));
            
            $stmt = $pdo->prepare("INSERT INTO produtos ($campos) VALUES ($placeholders)");
            $stmt->execute($dados_produto);
            
            $id_produto_novo = $pdo->lastInsertId();

            // Gerar EAN-13 se não informado
            if (empty($dados_produto['codigo_barras_ean'])) {
                $ean_base = str_pad($id_produto_novo, 12, '0', STR_PAD_LEFT);
                $ean13 = $ean_base . calcularDigitoEAN13($ean_base);

                $stmt = $pdo->prepare("UPDATE produtos SET codigo_barras_ean = :ean WHERE id_produto = :id_produto");
                $stmt->execute(['ean' => $ean13, 'id_produto' => $id_produto_novo]);
            }

            $pdo->commit();
            
            header("Location: dashboard.php?secao=produtos&msg=produto_ok");
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erro_produto = "Erro ao cadastrar produto: " . $e->getMessage();
        }
    }
}

// Processar edição de produto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_produto'])) {
    $id_produto = intval($_POST['id_produto']);
    $dados_produto = [
        'nome_produto' => trim($_POST['nome'] ?? ''),
        'descricao' => trim($_POST['descricao'] ?? ''),
        'codigo_barras_ean' => trim($_POST['codigo_barras'] ?? ''),
        'referencia_interna' => trim($_POST['referencia'] ?? ''),
        'unidade_medida' => trim($_POST['unidade_medida'] ?? 'UN'),
        'preco_venda' => floatval(str_replace(',', '.', $_POST['preco'] ?? 0)),
        'estoque_atual' => floatval(str_replace(',', '.', $_POST['estoque'] ?? 0)),
        'ncm' => trim($_POST['ncm'] ?? '01012100'),
        'ibpt' => floatval(str_replace(',', '.', $_POST['ibpt'] ?? 4.20)),
        'id_produto' => $id_produto,
        'id_loja' => $id_loja
    ];

    // Validação
    if (empty($dados_produto['referencia_interna']) || empty($dados_produto['nome_produto']) || 
        $dados_produto['preco_venda'] <= 0 || $dados_produto['estoque_atual'] < 0) {
        $erro_produto = "Preencha todos os campos obrigatórios corretamente!";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Upload da foto (se enviada)
            if (isset($_FILES['foto_produto']) && $_FILES['foto_produto']['error'] == UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_type = $_FILES['foto_produto']['type'];
                
                if (in_array($file_type, $allowed_types) && $_FILES['foto_produto']['size'] <= 2 * 1024 * 1024) {
                    $ext = pathinfo($_FILES['foto_produto']['name'], PATHINFO_EXTENSION);
                    $foto_nome = uniqid() . '.' . strtolower($ext);
                    $upload_dir = __DIR__ . '/uploads/imagens/';
                    
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    if (move_uploaded_file($_FILES['foto_produto']['tmp_name'], $upload_dir . $foto_nome)) {
                        $dados_produto['foto_produto'] = $foto_nome;
                    }
                }
            }
            
            // Montar SQL dinamicamente para atualizar a foto só se foi enviada
            $sql = "UPDATE produtos SET 
                nome_produto = :nome_produto,
                descricao = :descricao,
                codigo_barras_ean = :codigo_barras_ean,
                referencia_interna = :referencia_interna,
                unidade_medida = :unidade_medida,
                preco_venda = :preco_venda,
                estoque_atual = :estoque_atual,
                ncm = :ncm,
                ibpt = :ibpt";
            
            if (isset($dados_produto['foto_produto'])) {
                $sql .= ", foto_produto = :foto_produto";
            }
            
            $sql .= " WHERE id_produto = :id_produto AND id_loja = :id_loja";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($dados_produto);
            
            $pdo->commit();
            
            header("Location: dashboard.php?secao=produtos&msg=produto_atualizado");
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erro_produto = "Erro ao atualizar produto: " . $e->getMessage();
        }
    }
}

// Processar exclusão de produto
if (isset($_GET['excluir_produto'])) {
    $id_produto = intval($_GET['excluir_produto']);
    
    try {
        $pdo->beginTransaction();
        
        // Marcar como inativo em vez de excluir
        $stmt = $pdo->prepare("UPDATE produtos SET ativo = 0 WHERE id_produto = :id_produto AND id_loja = :id_loja");
        $stmt->execute([
            'id_produto' => $id_produto,
            'id_loja' => $id_loja
        ]);
        
        $pdo->commit();
        
        header("Location: dashboard.php?secao=produtos&msg=produto_excluido");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $erro_produto = "Erro ao excluir produto: " . $e->getMessage();
    }
}

// Buscar dados do produto para edição
if (isset($_GET['editar_produto'])) {
    $id_produto_editar = intval($_GET['editar_produto']);
    
    $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id_produto = :id_produto AND id_loja = :id_loja");
    $stmt->execute([
        'id_produto' => $id_produto_editar,
        'id_loja' => $id_loja
    ]);
    $produto_editar = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$produto_editar) {
        header("Location: dashboard.php?secao=produtos&msg=produto_nao_encontrado");
        exit;
    }
}

// Gerenciamento de Clientes
$erro_cliente = '';
$cliente_editar = null;

// Processar cadastro/edição de cliente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastro_cliente'])) {
    $dados_cliente = [
        'id_loja' => $id_loja,
        'nome_razao_social' => trim($_POST['nome'] ?? ''),
        'cpf_cnpj' => trim($_POST['cpf_cnpj'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'telefone' => trim($_POST['telefone'] ?? ''),
        'endereco' => trim($_POST['endereco'] ?? ''),
        'numero_endereco' => trim($_POST['numero'] ?? ''),
        'complemento_endereco' => trim($_POST['complemento'] ?? ''),
        'bairro' => trim($_POST['bairro'] ?? ''),
        'cidade' => trim($_POST['cidade'] ?? ''),
        'estado' => trim($_POST['estado'] ?? ''),
        'cep' => trim($_POST['cep'] ?? '')
    ];

    if (empty($dados_cliente['nome_razao_social'])) {
        $erro_cliente = "O nome/razão social é obrigatório!";
    } elseif (empty($dados_cliente['cidade'])) {
        $erro_cliente = "A cidade é obrigatória!";
    } elseif (empty($dados_cliente['estado'])) {
        $erro_cliente = "O estado é obrigatório!";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO clientes 
                (id_loja, nome_razao_social, cpf_cnpj, email, telefone, endereco, 
                numero_endereco, complemento_endereco, bairro, cidade, estado, cep)
                VALUES
                (:id_loja, :nome_razao_social, :cpf_cnpj, :email, :telefone, :endereco, 
                :numero_endereco, :complemento_endereco, :bairro, :cidade, :estado, :cep)");
            
            $stmt->execute($dados_cliente);
            
            header("Location: dashboard.php?secao=clientes&msg=cliente_ok");
            exit;
        } catch (PDOException $e) {
            $erro_cliente = "Erro ao cadastrar cliente: " . $e->getMessage();
        }
    }
}

// Processar edição de cliente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_cliente'])) {
    $id_cliente = intval($_POST['id_cliente']);
    $dados_cliente = [
        'nome_razao_social' => trim($_POST['nome'] ?? ''),
        'cpf_cnpj' => trim($_POST['cpf_cnpj'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'telefone' => trim($_POST['telefone'] ?? ''),
        'endereco' => trim($_POST['endereco'] ?? ''),
        'numero_endereco' => trim($_POST['numero'] ?? ''),
        'complemento_endereco' => trim($_POST['complemento'] ?? ''),
        'bairro' => trim($_POST['bairro'] ?? ''),
        'cidade' => trim($_POST['cidade'] ?? ''),
        'estado' => trim($_POST['estado'] ?? ''),
        'cep' => trim($_POST['cep'] ?? ''),
        'id_cliente' => $id_cliente,
        'id_loja' => $id_loja
    ];

    if (empty($dados_cliente['nome_razao_social'])) {
        $erro_cliente = "O nome/razão social é obrigatório!";
    } elseif (empty($dados_cliente['cidade'])) {
        $erro_cliente = "A cidade é obrigatória!";
    } elseif (empty($dados_cliente['estado'])) {
        $erro_cliente = "O estado é obrigatório!";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE clientes SET 
                nome_razao_social = :nome_razao_social,
                cpf_cnpj = :cpf_cnpj,
                email = :email,
                telefone = :telefone,
                endereco = :endereco,
                numero_endereco = :numero_endereco,
                complemento_endereco = :complemento_endereco,
                bairro = :bairro,
                cidade = :cidade,
                estado = :estado,
                cep = :cep
                WHERE id_cliente = :id_cliente AND id_loja = :id_loja");
            
            $stmt->execute($dados_cliente);
            
            header("Location: dashboard.php?secao=clientes&msg=cliente_atualizado");
            exit;
        } catch (PDOException $e) {
            $erro_cliente = "Erro ao atualizar cliente: " . $e->getMessage();
        }
    }
}

// Processar exclusão de cliente
if (isset($_GET['excluir_cliente'])) {
    $id_cliente = intval($_GET['excluir_cliente']);
    try {
        $stmt = $pdo->prepare("DELETE FROM clientes WHERE id_cliente = :id_cliente AND id_loja = :id_loja");
        $stmt->execute([
            'id_cliente' => $id_cliente,
            'id_loja' => $id_loja
        ]);
        header("Location: dashboard.php?secao=clientes&msg=cliente_excluido");
        exit;
    } catch (PDOException $e) {
        $erro_cliente = "Erro ao excluir cliente: " . $e->getMessage();
    }
}

// Processar upload da logo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_logo'])) {
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $nome_arquivo = 'logo_'.$id_loja.'.'.$ext;
        
        if (move_uploaded_file($_FILES['logo']['tmp_name'], 'uploads/logos/'.$nome_arquivo)) {
            // Atualizar no banco
            $stmt = $pdo->prepare("UPDATE lojas SET logo_path = ? WHERE id_loja = ?");
            $stmt->execute([$nome_arquivo, $id_loja]);
            
            header("Location: dashboard.php");
            exit;
        }
    }
}

// Buscar dados do cliente para edição
if (isset($_GET['editar_cliente'])) {
    $id_cliente_editar = intval($_GET['editar_cliente']);
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id_cliente = :id_cliente AND id_loja = :id_loja");
    $stmt->execute([
        'id_cliente' => $id_cliente_editar,
        'id_loja' => $id_loja
    ]);
    $cliente_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Paginação e busca de produtos
$produtos_por_pagina = 12;
$pagina_atual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($pagina_atual - 1) * $produtos_por_pagina;
$pesquisa = isset($_GET['pesquisa_produtos']) ? trim($_GET['pesquisa_produtos']) : '';
$mostrar_inativos = isset($_GET['mostrar_inativos']) ? 0 : 1;

// Contar total de produtos
try {
    $sql_count = "SELECT COUNT(*) FROM produtos WHERE id_loja = :id_loja AND ativo = :ativo";
    $params_count = [':id_loja' => $id_loja, ':ativo' => $mostrar_inativos];

    if (!empty($pesquisa)) {
        $pesquisa_sem_acentos = removerAcentos($pesquisa);
        $termo = "%" . str_replace(' ', '%', trim($pesquisa_sem_acentos)) . "%";
        $sql_count .= " AND (
            nome_produto LIKE :pesquisa_nome 
            OR referencia_interna LIKE :pesquisa_ref
            OR codigo_barras_ean = :codigo_exato
            OR descricao LIKE :pesquisa_desc
        )";
        $params_count[':pesquisa_nome'] = $termo;
        $params_count[':pesquisa_ref'] = $termo;
        $params_count[':codigo_exato'] = trim($pesquisa_sem_acentos);
        $params_count[':pesquisa_desc'] = $termo;
    }

    $stmt_count = $pdo->prepare($sql_count);
    foreach ($params_count as $key => $val) {
        $stmt_count->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt_count->execute();
    $total_produtos = (int)$stmt_count->fetchColumn();
    $total_paginas = max(1, ceil($total_produtos / $produtos_por_pagina));
} catch (PDOException $e) {
    error_log("Erro na contagem de produtos: " . $e->getMessage());
    $total_produtos = 0;
    $total_paginas = 1;
}

// Buscar produtos paginados
try {
    $sql_produtos = "SELECT * FROM produtos WHERE id_loja = :id_loja AND ativo = :ativo";
    $params_produtos = [':id_loja' => $id_loja, ':ativo' => $mostrar_inativos];

    if (!empty($pesquisa)) {
        $pesquisa_sem_acentos = removerAcentos($pesquisa);
        $termo = "%" . str_replace(' ', '%', trim($pesquisa_sem_acentos)) . "%";
        $sql_produtos .= " AND (
            nome_produto LIKE :pesquisa_nome 
            OR referencia_interna LIKE :pesquisa_ref
            OR codigo_barras_ean = :codigo_exato
            OR descricao LIKE :pesquisa_desc
        )";
        $params_produtos[':pesquisa_nome'] = $termo;
        $params_produtos[':pesquisa_ref'] = $termo;
        $params_produtos[':codigo_exato'] = trim($pesquisa_sem_acentos);
        $params_produtos[':pesquisa_desc'] = $termo;
    }

    $sql_produtos .= " ORDER BY nome_produto ASC LIMIT :limit OFFSET :offset";
    $params_produtos[':limit'] = $produtos_por_pagina;
    $params_produtos[':offset'] = $offset;

    $stmt_produtos = $pdo->prepare($sql_produtos);
    foreach ($params_produtos as $key => $val) {
        $stmt_produtos->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt_produtos->execute();
    $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar produtos: " . $e->getMessage());
    $produtos = [];
}

// Buscar clientes
$clientes = [];
try {
    $sql_clientes = "SELECT * FROM clientes WHERE id_loja = :id_loja";
    $params_clientes = [':id_loja' => $id_loja];

    if (!empty($_GET['pesquisa_clientes'] ?? '')) {
        $termo = "%" . trim($_GET['pesquisa_clientes']) . "%";
        $sql_clientes .= " AND (nome_razao_social LIKE :pesquisa_nome OR cpf_cnpj LIKE :pesquisa_cpf)";
        $params_clientes[':pesquisa_nome'] = $termo;
        $params_clientes[':pesquisa_cpf'] = $termo;
    }

    $sql_clientes .= " ORDER BY nome_razao_social";
    $stmt_clientes = $pdo->prepare($sql_clientes);
    foreach ($params_clientes as $key => $val) {
        $stmt_clientes->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt_clientes->execute();
    $clientes = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar clientes: " . $e->getMessage());
}

// Determinar seção ativa
$secao_ativa = $_GET['secao'] ?? 'dashboard';

// Dados para o dashboard
$caixa_aberto = false;
$vendas_hoje = ['total_vendas' => 0, 'total_pedidos' => 0];
$total_clientes = 0;
$total_produtos_ativos = 0;
$ultimas_vendas = [];

try {
    // Verificar status do caixa
    $stmt_caixa = $pdo->prepare("SELECT * FROM caixa WHERE cnpj_loja = :cnpj_loja ORDER BY id DESC LIMIT 1");
    $stmt_caixa->bindParam(':cnpj_loja', $cnpj_loja, PDO::PARAM_STR);
    $stmt_caixa->execute();
    $caixa = $stmt_caixa->fetch(PDO::FETCH_ASSOC);
    $caixa_aberto = ($caixa && $caixa['status'] == 'aberto');

    // Vendas do dia
    if ($caixa_aberto) {
        $stmt_vendas = $pdo->prepare("
            SELECT 
                COALESCE(SUM(valor_total_venda),0) AS total_vendas, 
                COUNT(*) AS total_pedidos
            FROM vendas
            WHERE cnpj_loja = :cnpj_loja
              AND DATE(data_hora_venda) = CURDATE()
              AND status_venda = 'CONCLUIDA'
              AND data_hora_venda >= :data_abertura
        ");
        $stmt_vendas->bindParam(':cnpj_loja', $cnpj_loja, PDO::PARAM_STR);
        $stmt_vendas->bindParam(':data_abertura', $caixa['data_abertura'], PDO::PARAM_STR);
        $stmt_vendas->execute();
        $vendas_hoje = $stmt_vendas->fetch(PDO::FETCH_ASSOC);
    }

    // Total de clientes
    $stmt_clientes = $pdo->prepare("SELECT COUNT(*) AS total_clientes FROM clientes WHERE id_loja = :id_loja");
    $stmt_clientes->bindParam(':id_loja', $id_loja, PDO::PARAM_INT);
    $stmt_clientes->execute();
    $total_clientes = $stmt_clientes->fetchColumn();

    // Total de produtos ativos
    $stmt_produtos = $pdo->prepare("SELECT COUNT(*) AS total_produtos FROM produtos WHERE id_loja = :id_loja AND ativo = 1");
    $stmt_produtos->bindParam(':id_loja', $id_loja, PDO::PARAM_INT);
    $stmt_produtos->execute();
    $total_produtos_ativos = $stmt_produtos->fetchColumn();

    // Últimas vendas
    $stmt_ultimas = $pdo->prepare("
        SELECT v.id_venda, v.data_hora_venda, v.valor_total_venda, 
               COALESCE(c.nome_razao_social, 'Consumidor Final') AS cliente,
               v.status_venda
        FROM vendas v
        LEFT JOIN clientes c ON v.id_cliente = c.id_cliente
        WHERE v.cnpj_loja = :cnpj_loja
        ORDER BY v.data_hora_venda DESC
        LIMIT 5
    ");
    $stmt_ultimas->bindParam(':cnpj_loja', $cnpj_loja, PDO::PARAM_STR);
    $stmt_ultimas->execute();
    $ultimas_vendas = $stmt_ultimas->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar dados do dashboard: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= htmlspecialchars($nome_loja) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background-color: #f8fafc;
            color: var(--gray-900);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        /* Sidebar */
        .sidebar {
            width: 240px;
            background: var(--sidebar);
            color: white;
            height: 100vh;
            position: fixed;
            padding: 1.5rem 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
            transition: var(--transition);
            box-shadow: var(--card-shadow);
        }
        
        .logo {
            text-align: center;
            padding: 0 1.5rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .logo h2 {
            color: white;
            font-size: 1.25rem;
            margin-top: 0.5rem;
            font-weight: 600;
        }
        
        .nav-menu {
            margin-top: 1.5rem;
            flex: 1;
            overflow-y: auto;
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            margin: 0.25rem 0.75rem;
            border-radius: 0.5rem;
            color: white;
            text-decoration: none;
            transition: var(--transition);
            font-weight: 500;
        }
        
        .nav-item:hover, .nav-item.active {
            background: var(--sidebar-active);
            transform: translateX(5px);
        }
        
        .nav-item.active {
            border-left: 4px solid var(--primary);
        }
        
        .nav-item i {
            margin-right: 0.75rem;
            width: 1.25rem;
            text-align: center;
            font-size: 1.1rem;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 240px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: linear-gradient(135deg, #f5f7ff 0%, #eef2ff 100%);
        }
        
        /* Topbar */
        .topbar {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 50;
        }
        
        .topbar .info {
            font-size: 0.9rem;
            color: var(--gray-700);
        }
        
        .topbar .info strong {
            color: var(--primary-dark);
            font-weight: 600;
        }
        
        .logout-btn {
            background: var(--danger);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            transition: var(--transition);
            font-weight: 500;
        }
        
        .logout-btn:hover {
            background: var(--danger-dark);
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .logout-btn i {
            margin-right: 0.5rem;
        }
        
        /* Cards */
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.07);
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
            animation: fadeIn 0.8s ease;
        }
        
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.1);
        }
        
        .card.sales {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .card.clients {
            background: linear-gradient(135deg, #ff5858 0%, #f09819 100%);
            color: white;
        }
        
        .card.products {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        
        .card::before {
            content: "";
            position: absolute;
            top: -50px;
            right: -20px;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
        }
        
        .card::after {
            content: "";
            position: absolute;
            top: -30px;
            right: 20px;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
        }
        
        .card-content {
            position: relative;
            z-index: 2;
        }
        
        .card .title {
            font-size: 18px;
            margin-bottom: 15px;
            font-weight: 500;
            opacity: 0.9;
        }
        
        .card .value {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }
        
        .card .desc {
            font-size: 15px;
            opacity: 0.85;
            margin-bottom: 15px;
        }
        
        .card .trend {
            display: flex;
            align-items: center;
            font-size: 14px;
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 12px;
            border-radius: 20px;
            width: fit-content;
        }
        
        .card .trend i {
            margin-right: 5px;
        }
        
        /* Relatórios Section */
        .reports-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.07);
            animation: fadeIn 1s ease;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .section-title {
            font-size: 1.4rem;
            color: var(--gray-900);
            font-weight: 600;
        }
        
        .generate-report {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }
        
        .generate-report:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .generate-report i {
            margin-right: 8px;
        }
        
        .report-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }
        
        .report-card {
            background: #f9faff;
            border: 1px solid #e6e9ff;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .report-card:hover {
            transform: translateY(-5px);
            background: #f0f4ff;
            border-color: var(--primary);
            box-shadow: 0 5px 15px rgba(74, 107, 255, 0.1);
        }
        
        .report-card i {
            font-size: 28px;
            color: var(--primary);
            margin-bottom: 15px;
        }
        
        .report-card h3 {
            font-size: 18px;
            margin-bottom: 10px;
            color: var(--gray-900);
        }
        
        .report-card p {
            font-size: 14px;
            color: #666;
            line-height: 1.5;
        }
        
        /* Tabela de Vendas Recentes */
        .recent-sales {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.07);
            margin-top: 30px;
            animation: fadeIn 1.2s ease;
        }
        
        .sales-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .sales-table th {
            background: var(--primary);
            color: white;
            padding: 12px 15px;
            text-align: left;
            position: sticky;
            top: 0;
        }
        
        .sales-table td {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
        }
        
        .sales-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .sales-table tr:hover {
            background-color: #eef2ff;
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
            min-width: 100px;
            text-align: center;
        }
        
        .status-CONCLUIDA {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .status-EM_ABERTO {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        .status-CANCELADA {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Seções Dinâmicas */
        .secao-conteudo {
            display: none;
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.07);
            margin-top: 30px;
            animation: fadeIn 0.5s ease;
        }
        
        .secao-conteudo.ativa {
            display: block;
        }
        
        /* Formulários e Pesquisa */
        .pesquisa-container {
            display: flex;
            margin-bottom: 20px;
            gap: 15px;
        }
        
        .pesquisa-input {
            flex: 1;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: var(--transition);
        }
        
        .pesquisa-input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(74, 107, 255, 0.1);
        }
        
        .btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn i {
            margin-right: 8px;
        }
        
        .btn-success {
            background: var(--success);
        }
        
        .btn-success:hover {
            background: var(--success-dark);
        }
        
        /* Tabelas de dados */
        .dados-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .dados-table th {
            background: var(--primary);
            color: white;
            padding: 12px 15px;
            text-align: left;
            position: sticky;
            top: 0;
        }
        
        .dados-table td {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        
        .dados-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .dados-table tr:hover {
            background-color: #eef2ff;
        }
        
        /* Botões de ação */
        .btn-action {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            display: inline-flex;
            align-items: center;
        }
        
        .btn-edit {
            background: var(--info);
            color: white;
        }
        
        .btn-edit:hover {
            background: var(--info-dark);
        }
        
        .btn-delete {
            background: var(--danger);
            color: white;
        }
        
        .btn-delete:hover {
            background: var(--danger-dark);
        }
        
        .btn-action i {
            margin-right: 5px;
        }
        
        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            display: none;
        }
        
        .modal {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            position: relative;
            animation: modalFadeIn 0.3s ease;
        }
        
        .modal-header {
            padding: 20px 30px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 1.5rem;
            color: var(--gray-900);
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 24px;
            color: #999;
            transition: all 0.3s ease;
        }
        
        .modal-close:hover {
            color: var(--danger);
            transform: rotate(90deg);
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #444;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: var(--transition);
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(74, 107, 255, 0.1);
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-col {
            flex: 1;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
        }
        
        /* Imagem do produto */
        .imagem-container {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .imagem-preview {
            max-width: 200px;
            max-height: 200px;
            border-radius: 8px;
            border: 2px dashed #ddd;
            padding: 10px;
        }
        
        /* Estilos para o modal de relatório */
        .modal-relatorio {
            max-width: 1000px;
            width: 95%;
        }
        
        .relatorio-header {
            background: var(--primary);
            color: white;
            padding: 20px;
            border-radius: 16px 16px 0 0;
        }
        
        .relatorio-body {
            padding: 20px;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .relatorio-totais {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .relatorio-total-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .relatorio-total-card h3 {
            font-size: 16px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .relatorio-total-card .valor {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary);
        }
        
        .relatorio-periodo {
            text-align: center;
            margin-bottom: 20px;
            font-size: 18px;
            color: #555;
            background: #f5f7ff;
            padding: 10px;
            border-radius: 8px;
        }
        
        .relatorio-tabela {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .relatorio-tabela th {
            background: var(--primary);
            color: white;
            padding: 12px;
            text-align: left;
        }
        
        .relatorio-tabela td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
        }
        
        .relatorio-tabela tr:hover {
            background: #f5f7ff;
        }
        
        .relatorio-produtos {
            margin-top: 30px;
        }
        
        .relatorio-produtos h3 {
            border-bottom: 2px solid var(--primary);
            padding-bottom: 8px;
            margin-bottom: 15px;
            color: #333;
        }
        
        /* Calendário */
        .date-picker-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .date-picker-group {
            flex: 1;
            min-width: 200px;
        }
        
        .date-picker-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #444;
        }
        
        .date-picker-input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
        }
        
        /* Paginação */
        .paginacao {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagina-atual {
            padding: 10px 15px;
            background: #f0f0f0;
            border-radius: 5px;
        }
        
        /* Mensagens */
        .mensagem {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
        }
        
        .mensagem-sucesso {
            background: #d4edda;
            color: #155724;
        }
        
        .mensagem-erro {
            background: #f8d7da;
            color: #721c24;
        }
        
        .mensagem i {
            margin-right: 10px;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(50px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .animated {
            animation-duration: 0.6s;
            animation-fill-mode: both;
        }
        
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        
        /* Estilos para impressão */
        @media print {
            body * {
                visibility: hidden;
            }
            .modal-relatorio, .modal-relatorio * {
                visibility: visible;
            }
            .modal-relatorio {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                max-width: 100%;
                box-shadow: none;
                border: none;
            }
            .modal-header, .form-actions, .modal-close {
                display: none !important;
            }
            .relatorio-header {
                border-radius: 0;
                margin-bottom: 20px;
            }
            .relatorio-tabela {
                page-break-inside: avoid;
            }
            @page {
                size: auto;
                margin: 10mm;
            }
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            body {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                min-height: auto;
                padding: 15px 0;
            }
            
            .nav-menu {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                margin-top: 15px;
            }
            
            .nav-item {
                margin: 5px;
                padding: 10px 15px;
                border-radius: 8px;
                border-left: none;
                border-bottom: 3px solid transparent;
            }
            
            .nav-item:hover, .nav-item.active {
                border-left: none;
                border-bottom: 3px solid var(--primary);
            }
            
            .main-content {
                padding: 20px;
                margin-left: 0;
            }
        }
        
        @media (max-width: 768px) {
            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .topbar .info {
                margin-bottom: 15px;
            }
            
            .logout-btn {
                width: 100%;
                justify-content: center;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .generate-report {
                margin-top: 15px;
                width: 100%;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .relatorio-totais {
                grid-template-columns: 1fr;
            }
            
            .pesquisa-container {
                flex-direction: column;
            }
        }
        
        @media (max-width: 576px) {
            .dados-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            
            .modal {
                width: 95%;
                max-width: none;
            }
        }

        .logo img {
    max-height: 80px;
    max-width: 100%;
    object-fit: contain;
    margin-bottom: 10px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.modal-body img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    margin: 10px 0;
}
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
    <?php if($logo_loja): ?>
        <img src="uploads/logos/<?= htmlspecialchars($logo_loja) ?>" 
             style="max-height: 80px; margin-bottom: 10px;">
    <?php else: ?>
        <i class="fas fa-store-alt fa-2x"></i>
    <?php endif; ?>
    <h2><?= htmlspecialchars($nome_loja) ?></h2>
</div>
        
        <div class="nav-menu">
            <a href="?secao=dashboard" class="nav-item <?= $secao_ativa == 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="caixa.php" class="nav-item">
                <i class="fas fa-cash-register"></i>
                <span>Vendas</span>
            </a>
            <a href="?secao=produtos" class="nav-item <?= $secao_ativa == 'produtos' ? 'active' : '' ?>">
                <i class="fas fa-box-open"></i>
                <span>Produtos</span>
            </a>
            <a href="etiquetas.php" class="nav-item">
                <i class="fas fa-tags"></i>
                <span>Etiquetas</span>
            </a>
            <a href="?secao=clientes" class="nav-item <?= $secao_ativa == 'clientes' ? 'active' : '' ?>">
                <i class="fas fa-users"></i>
                <span>Clientes</span>
            </a>

        <a href="#" onclick="abrirModalLogo(); return false;" class="nav-item">
    <i class="fas fa-image"></i>
    <span>Adicionar Logo</span>
</a>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="topbar animated">
            <div class="info">
                Usuário: <strong><?= htmlspecialchars($usuario) ?></strong> | 
                CNPJ: <strong><?= htmlspecialchars($cnpj_loja) ?></strong> | 
                Loja: <strong><?= htmlspecialchars($nome_loja) ?></strong>
            </div>
            <form action="login.php" method="post" style="margin:0;">
                <button type="submit" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Sair
                </button>
            </form>
        </div>
        
        <!-- Mensagens -->
        <?php if(isset($_GET['msg'])): ?>
            <?php if($_GET['msg'] == 'produto_ok'): ?>
                <div class="mensagem mensagem-sucesso animated">
                    <i class="fas fa-check-circle"></i>
                    <span>Produto cadastrado com sucesso!</span>
                </div>
            <?php elseif($_GET['msg'] == 'produto_atualizado'): ?>
                <div class="mensagem mensagem-sucesso animated">
                    <i class="fas fa-check-circle"></i>
                    <span>Produto atualizado com sucesso!</span>
                </div>
            <?php elseif($_GET['msg'] == 'produto_excluido'): ?>
                <div class="mensagem mensagem-sucesso animated">
                    <i class="fas fa-check-circle"></i>
                    <span>Produto excluído com sucesso!</span>
                </div>
            <?php elseif($_GET['msg'] == 'cliente_ok'): ?>
                <div class="mensagem mensagem-sucesso animated">
                    <i class="fas fa-check-circle"></i>
                    <span>Cliente cadastrado com sucesso!</span>
                </div>
            <?php elseif($_GET['msg'] == 'cliente_atualizado'): ?>
                <div class="mensagem mensagem-sucesso animated">
                    <i class="fas fa-check-circle"></i>
                    <span>Cliente atualizado com sucesso!</span>
                </div>
            <?php elseif($_GET['msg'] == 'cliente_excluido'): ?>
                <div class="mensagem mensagem-sucesso animated">
                    <i class="fas fa-check-circle"></i>
                    <span>Cliente excluído com sucesso!</span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if(!empty($erro_produto)): ?>
            <div class="mensagem mensagem-erro animated">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($erro_produto) ?></span>
            </div>
        <?php endif; ?>
        
        <?php if(!empty($erro_cliente)): ?>
            <div class="mensagem mensagem-erro animated">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($erro_cliente) ?></span>
            </div>
        <?php endif; ?>
        
        <?php if(!empty($erro_relatorio)): ?>
            <div class="mensagem mensagem-erro animated">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($erro_relatorio) ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Dashboard -->
        <div class="secao-conteudo <?= $secao_ativa == 'dashboard' ? 'ativa' : '' ?>" id="dashboard">
            <div class="cards">
                <div class="card sales animated delay-1">
                    <div class="card-content">
                        <div class="title">Vendas de Hoje</div>
                        <div class="value">R$ <?= number_format($vendas_hoje['total_vendas'] ?? 0, 2, ',', '.') ?></div>
                        <div class="desc">Pedidos: <?= $vendas_hoje['total_pedidos'] ?? 0 ?></div>
                        <div class="trend">
                            <?php if(($vendas_hoje['total_pedidos'] ?? 0) > 0): ?>
                                <i class="fas fa-arrow-up"></i> Hoje
                            <?php else: ?>
                                <i class="fas fa-clock"></i> <?= $caixa_aberto ? 'Sem vendas hoje' : 'Caixa fechado' ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="card clients animated delay-2">
                    <div class="card-content">
                        <div class="title">Clientes Cadastrados</div>
                        <div class="value"><?= $total_clientes ?></div>
                        <div class="desc">Total de clientes da loja</div>
                        <div class="trend">
                            <i class="fas fa-user-plus"></i> Registrados
                        </div>
                    </div>
                </div>
                
                <div class="card products animated delay-3">
                    <div class="card-content">
                        <div class="title">Produtos em Estoque</div>
                        <div class="value"><?= $total_produtos_ativos ?></div>
                        <div class="desc">Itens cadastrados</div>
                        <div class="trend">
                            <i class="fas fa-box"></i> Disponíveis
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="reports-section animated delay-2">
                <div class="section-header">
                    <h2 class="section-title">Relatórios</h2>
                    <button class="generate-report" onclick="abrirModalPeriodo()">
                        <i class="fas fa-file-download"></i> Gerar Relatório Completo
                    </button>
                </div>
                
                <div class="report-cards">
                    <div class="report-card" onclick="gerarRelatorioRapido('hoje')">
                        <i class="fas fa-chart-bar"></i>
                        <h3>Vendas de Hoje</h3>
                        <p>Relatório detalhado de vendas realizadas hoje.</p>
                    </div>
                    
                    <div class="report-card" onclick="gerarRelatorioRapido('semana')">
                        <i class="fas fa-calendar-week"></i>
                        <h3>Vendas da Semana</h3>
                        <p>Vendas dos últimos 7 dias com análise comparativa.</p>
                    </div>
                    
                    <div class="report-card" onclick="gerarRelatorioRapido('mes')">
                        <i class="fas fa-calendar-alt"></i>
                        <h3>Vendas do Mês</h3>
                        <p>Relatório mensal com análise de desempenho.</p>
                    </div>
                    
                    <div class="report-card" onclick="abrirModalPeriodo()">
                        <i class="fas fa-calendar-day"></i>
                        <h3>Período Personalizado</h3>
                        <p>Selecione um período específico para análise.</p>
                    </div>
                </div>
            </div>
            
            <div class="recent-sales animated delay-3">
                <div class="section-header">
                    <h2 class="section-title">Últimas Vendas</h2>
                </div>
                
                <table class="sales-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Data/Hora</th>
                            <th>Cliente</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($ultimas_vendas) > 0): ?>
                            <?php foreach($ultimas_vendas as $venda): ?>
                                <tr>
                                    <td>#<?= $venda['id_venda'] ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($venda['data_hora_venda'])) ?></td>
                                    <td><?= htmlspecialchars($venda['cliente']) ?></td>
                                    <td>R$ <?= number_format($venda['valor_total_venda'], 2, ',', '.') ?></td>
                                    <td>
                                        <span class="status-badge status-<?= $venda['status_venda'] ?>">
                                            <?= $venda['status_venda'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn-action btn-edit" title="Ver detalhes">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if($venda['status_venda'] == 'EM_ABERTO'): ?>
                                            <button class="btn-action btn-delete" title="Cancelar venda">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center;">Nenhuma venda encontrada</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Seção de Produtos -->
        <div class="secao-conteudo <?= $secao_ativa == 'produtos' ? 'ativa' : '' ?>" id="produtos">
            <div class="section-header">
                <h2 class="section-title">Gerenciamento de Produtos</h2>
<button class="btn btn-success" onclick="abrirModalProduto(); return false;">
    <i class="fas fa-plus"></i> Cadastrar Produto
</button>
            </div>
            
            <form method="get" class="pesquisa-container">
                <input type="hidden" name="secao" value="produtos">
                <input type="text" name="pesquisa_produtos" class="pesquisa-input" 
                       placeholder="Pesquisar produto por nome, código ou descrição..." 
                       value="<?= htmlspecialchars($_GET['pesquisa_produtos'] ?? '') ?>">
                <button type="submit" class="btn">
                    <i class="fas fa-search"></i> Pesquisar
                </button>
            </form>
            
            <table class="dados-table">
                <thead>
                    <tr>
                        <th>Foto</th>
                        <th>Código</th>
                        <th>Nome do Produto</th>
                        <th>Preço</th>
                        <th>Estoque</th>
                        <th>NCM</th>
                        <th>IBPT</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($produtos) > 0): ?>
                        <?php foreach($produtos as $produto): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($produto['foto_produto'])): ?>
                                        <img src="uploads/imagens/<?= htmlspecialchars($produto['foto_produto']) ?>" 
                                             style="max-width:60px;max-height:60px;border-radius:6px;" 
                                             alt="<?= htmlspecialchars($produto['nome_produto']) ?>">
                                    <?php else: ?>
                                        <span style="color:#aaa;">Sem foto</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($produto['referencia_interna'] ?? '') ?></td>
                                <td><?= htmlspecialchars($produto['nome_produto'] ?? '') ?></td>
                                <td>R$ <?= number_format($produto['preco_venda'] ?? 0, 2, ',', '.') ?></td>
                                <td><?= number_format($produto['estoque_atual'] ?? 0, 3, ',', '.') ?></td>
                                <td><?= htmlspecialchars($produto['ncm'] ?? '') ?></td>
                                <td><?= number_format($produto['ibpt'] ?? 0, 2, ',', '.') ?>%</td>
                                <td>
                                    <button class="btn-action btn-edit" 
                                            onclick="editarProduto(<?= $produto['id_produto'] ?>)"
                                            title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-action btn-delete" 
                                            onclick="confirmarExclusao(<?= $produto['id_produto'] ?>, 'produto')"
                                            title="Excluir">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center;">Nenhum produto encontrado</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if($total_paginas > 1): ?>
                <div class="paginacao">
                    <?php if($pagina_atual > 1): ?>
                        <a href="?secao=produtos&pagina=<?= $pagina_atual - 1 ?>&pesquisa_produtos=<?= urlencode($pesquisa) ?>" class="btn">
                            <i class="fas fa-chevron-left"></i> Anterior
                        </a>
                    <?php endif; ?>
                    
                    <span class="pagina-atual">
                        Página <?= $pagina_atual ?> de <?= $total_paginas ?>
                    </span>
                    
                    <?php if($pagina_atual < $total_paginas): ?>
                        <a href="?secao=produtos&pagina=<?= $pagina_atual + 1 ?>&pesquisa_produtos=<?= urlencode($pesquisa) ?>" class="btn">
                            Próxima <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Seção de Clientes -->
        <div class="secao-conteudo <?= $secao_ativa == 'clientes' ? 'ativa' : '' ?>" id="clientes">
            <div class="section-header">
                <h2 class="section-title">Gerenciamento de Clientes</h2>
                <button class="btn btn-success" onclick="abrirModalCliente()">
                    <i class="fas fa-plus"></i> Cadastrar Cliente
                </button>
            </div>
            
            <form method="get" class="pesquisa-container">
                <input type="hidden" name="secao" value="clientes">
                <input type="text" name="pesquisa_clientes" class="pesquisa-input" 
                       placeholder="Pesquisar cliente por nome ou CPF/CNPJ..." 
                       value="<?= htmlspecialchars($_GET['pesquisa_clientes'] ?? '') ?>">
                <button type="submit" class="btn">
                    <i class="fas fa-search"></i> Pesquisar
                </button>
            </form>
            
            <table class="dados-table">
                <thead>
                    <tr>
                        <th>Nome/Razão Social</th>
                        <th>CPF/CNPJ</th>
                        <th>Telefone</th>
                        <th>Cidade</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($clientes) > 0): ?>
                        <?php foreach($clientes as $cliente): ?>
                            <tr>
                                <td><?= htmlspecialchars($cliente['nome_razao_social'] ?? '') ?></td>
                                <td><?= htmlspecialchars($cliente['cpf_cnpj'] ?? 'Não informado') ?></td>
                                <td><?= htmlspecialchars($cliente['telefone'] ?? 'Não informado') ?></td>
                                <td><?= htmlspecialchars($cliente['cidade'] ?? '') ?></td>
                                <td>
                                    <button class="btn-action btn-edit" 
                                            onclick="editarCliente(<?= $cliente['id_cliente'] ?>)"
                                            title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-action btn-delete" 
                                            onclick="confirmarExclusao(<?= $cliente['id_cliente'] ?>, 'cliente')"
                                            title="Excluir">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">Nenhum cliente encontrado</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Modal de Produto -->
    <div class="modal-overlay" id="modalProduto">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="modalProdutoTitulo">Cadastrar Produto</h3>
                <button class="modal-close" onclick="fecharModalProduto()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="formProduto" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="cadastro_produto" id="acao_produto" value="1">
                    <input type="hidden" name="id_produto" id="id_produto" value="">
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Código de Referência *</label>
                                <input type="text" name="referencia" id="referencia" class="form-control" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Nome do Produto *</label>
                                <input type="text" name="nome" id="nome" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Descrição</label>
                        <textarea name="descricao" id="descricao" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Preço de Venda *</label>
                                <input type="number" name="preco" id="preco" class="form-control" step="0.01" min="0.01" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Estoque Atual *</label>
                                <input type="number" name="estoque" id="estoque" class="form-control" step="1" min="0" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Código de Barras (EAN)</label>
                                <input type="text" name="codigo_barras" id="codigo_barras" class="form-control">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Unidade de Medida</label>
                                <select name="unidade_medida" id="unidade_medida" class="form-control">
                                    <option value="UN">UN - Unidade</option>
                                    <option value="CX">CX - Caixa</option>
                                    <option value="KG">KG - Quilograma</option>
                                    <option value="LT">LT - Litro</option>
                                    <option value="MT">MT - Metro</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">NCM *</label>
                                <input type="text" name="ncm" id="ncm" class="form-control" required value="01012100">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Alíquota IBPT *</label>
                                <input type="number" name="ibpt" id="ibpt" class="form-control" step="0.01" min="0" required value="4.20">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Foto do Produto</label>
                        <input type="file" name="foto_produto" id="foto_produto" class="form-control" accept="image/*">
                    </div>
                    
                    <div class="form-group" id="imagemPreviewContainer" style="display:none;">
                        <label class="form-label">Imagem Atual:</label><br>
                        <img id="imagemPreview" src="" alt="Foto do Produto" style="max-width:250px;max-height:250px;border-radius:8px;border:1px solid #ccc;">
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-cancel" onclick="fecharModalProduto()">Cancelar</button>
                        <button type="submit" class="btn btn-success">Salvar Produto</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de Cliente -->
    <div class="modal-overlay" id="modalCliente">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title"><?= $cliente_editar ? 'Editar Cliente' : 'Cadastrar Cliente' ?></h3>
                <button class="modal-close" onclick="fecharModalCliente()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="formCliente" method="post">
                    <?php if($cliente_editar): ?>
                        <input type="hidden" name="editar_cliente" value="1">
                        <input type="hidden" name="id_cliente" value="<?= $cliente_editar['id_cliente'] ?>">
                    <?php else: ?>
                        <input type="hidden" name="cadastro_cliente" value="1">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Nome/Razão Social *</label>
                                <input type="text" name="nome" class="form-control" required
                                       value="<?= $cliente_editar['nome_razao_social'] ?? '' ?>">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">CPF/CNPJ</label>
                                <input type="text" name="cpf_cnpj" class="form-control"
                                       value="<?= $cliente_editar['cpf_cnpj'] ?? '' ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control"
                                       value="<?= $cliente_editar['email'] ?? '' ?>">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Telefone</label>
                                <input type="tel" name="telefone" class="form-control"
                                       value="<?= $cliente_editar['telefone'] ?? '' ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Endereço</label>
                        <input type="text" name="endereco" class="form-control"
                               value="<?= $cliente_editar['endereco'] ?? '' ?>">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Número</label>
                                <input type="text" name="numero" class="form-control"
                                       value="<?= $cliente_editar['numero_endereco'] ?? '' ?>">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Complemento</label>
                                <input type="text" name="complemento" class="form-control"
                                       value="<?= $cliente_editar['complemento_endereco'] ?? '' ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Bairro</label>
                                <input type="text" name="bairro" class="form-control"
                                       value="<?= $cliente_editar['bairro'] ?? '' ?>">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Cidade *</label>
                                <input type="text" name="cidade" class="form-control" required
                                       value="<?= $cliente_editar['cidade'] ?? '' ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">Estado *</label>
                                <input type="text" name="estado" class="form-control" maxlength="2" required
                                       value="<?= $cliente_editar['estado'] ?? '' ?>">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="form-label">CEP</label>
                                <input type="text" name="cep" class="form-control"
                                       value="<?= $cliente_editar['cep'] ?? '' ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-cancel" onclick="fecharModalCliente()">Cancelar</button>
                        <button type="submit" class="btn btn-success">Salvar Cliente</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de Seleção de Período -->
    <div class="modal-overlay" id="modalPeriodo">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Selecione o Período</h3>
                <button class="modal-close" onclick="fecharModalPeriodo()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="formPeriodo" method="post">
                    <input type="hidden" name="gerar_relatorio" value="1">
                    
                    <div class="date-picker-container">
                        <div class="date-picker-group">
                            <label class="date-picker-label">Data Inicial</label>
                            <input type="text" name="data_inicio" id="data_inicio" class="date-picker-input" required>
                        </div>
                        
                        <div class="date-picker-group">
                            <label class="date-picker-label">Data Final</label>
                            <input type="text" name="data_fim" id="data_fim" class="date-picker-input" required>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-cancel" onclick="fecharModalPeriodo()">Cancelar</button>
                        <button type="submit" class="btn btn-success">Gerar Relatório</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de Relatório -->
    <div class="modal-overlay" id="modalRelatorio">
        <div class="modal modal-relatorio">
            <div class="relatorio-header">
                <h3 class="modal-title">Relatório de Vendas</h3>
                <button class="modal-close" onclick="fecharModalRelatorio()">&times;</button>
            </div>
            <div class="relatorio-body">
                <?php if(!empty($relatorio_data)): ?>
                    <div class="relatorio-periodo animated">
                        Período: <?= $relatorio_data['data_inicio'] ?> a <?= $relatorio_data['data_fim'] ?>
                    </div>
                    
                    <div class="relatorio-totais animated delay-1">
                        <div class="relatorio-total-card">
                            <h3>Total de Vendas</h3>
                            <div class="valor">R$ <?= number_format($relatorio_data['total_vendas'], 2, ',', '.') ?></div>
                        </div>
                        
                        <div class="relatorio-total-card">
                            <h3>Total de Pedidos</h3>
                            <div class="valor"><?= $relatorio_data['total_pedidos'] ?></div>
                        </div>
                        
                        <div class="relatorio-total-card">
                            <h3>Ticket Médio</h3>
                            <div class="valor">R$ <?= $relatorio_data['total_pedidos'] > 0 ? number_format($relatorio_data['total_vendas'] / $relatorio_data['total_pedidos'], 2, ',', '.') : '0,00' ?></div>
                        </div>
                    </div>
                    
                    <div class="animated delay-2">
                        <h3>Vendas Realizadas</h3>
                        <table class="relatorio-tabela">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Data/Hora</th>
                                    <th>Cliente</th>
                                    <th>Valor</th>
                                    <th>Forma Pagamento</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($relatorio_data['vendas'] as $venda): ?>
                                    <tr>
                                        <td>#<?= $venda['id_venda'] ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($venda['data_hora_venda'])) ?></td>
                                        <td><?= htmlspecialchars($venda['nome_razao_social'] ?? 'Consumidor Final') ?></td>
                                        <td>R$ <?= number_format($venda['valor_total_venda'], 2, ',', '.') ?></td>
                                        <td><?= htmlspecialchars($venda['formas_pagamento'] ?? 'Não informado') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if(!empty($relatorio_data['produtos_mais_vendidos'])): ?>
                    <div class="relatorio-produtos animated delay-3">
                        <h3>Produtos Mais Vendidos</h3>
                        <table class="relatorio-tabela">
                            <thead>
                                <tr>
                                    <th>Produto</th>
                                    <th>Código</th>
                                    <th>Quantidade</th>
                                    <th>Total Faturado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($relatorio_data['produtos_mais_vendidos'] as $produto): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($produto['nome_produto']) ?></td>
                                        <td><?= htmlspecialchars($produto['referencia_interna']) ?></td>
                                        <td><?= number_format($produto['total_vendido'], 3, ',', '.') ?></td>
                                        <td>R$ <?= number_format($produto['total_faturado'], 2, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-actions animated delay-3">
                        <button type="button" class="btn btn-cancel" onclick="fecharModalRelatorio()">Fechar</button>
                        <button type="button" class="btn" onclick="imprimirRelatorio()">
                            <i class="fas fa-print"></i> Imprimir Relatório
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmação -->
    <div class="modal-overlay" id="modalConfirmacao">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header">
                <h3 class="modal-title">Confirmação</h3>
                <button class="modal-close" onclick="fecharModalConfirmacao()">&times;</button>
            </div>
            <div class="modal-body">
                <p id="mensagemConfirmacao" style="text-align: center; font-size: 16px; margin-bottom: 20px;"></p>
                <div style="display: flex; justify-content: center; gap: 15px;">
                    <button onclick="confirmarAcao()" class="btn btn-success" style="min-width: 80px;">Sim</button>
                    <button onclick="fecharModalConfirmacao()" class="btn btn-cancel" style="min-width: 80px;">Não</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para upload da logo -->
<div class="modal-overlay" id="modalLogo">
    <div class="modal">
        <div class="modal-header">
            <h3>Adicionar Logo</h3>
            <button onclick="fecharModalLogo()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="upload_logo" value="1">
                <input type="file" name="logo" accept="image/*" required>
                <button type="submit">Salvar</button>
            </form>
        </div>
    </div>
</div>

<script>
function abrirModalLogo() {
    document.getElementById('modalLogo').style.display = 'flex';
}
function fecharModalLogo() {
    document.getElementById('modalLogo').style.display = 'none';
}
</script>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/pt.js"></script>
    <script>
        // Variáveis globais
        let idParaExcluir = null;
        let tipoExclusao = null;
        let acaoConfirmada = false;
        
        // Inicializar datepickers
        document.addEventListener('DOMContentLoaded', function() {
            flatpickr("#data_inicio", {
                dateFormat: "Y-m-d",
                locale: "pt",
                defaultDate: "<?= date('Y-m-01') ?>"
            });
            
            flatpickr("#data_fim", {
                dateFormat: "Y-m-d",
                locale: "pt",
                defaultDate: "<?= date('Y-m-t') ?>"
            });

            // Iniciar funções de atualização
            atualizarVendas();
            atualizarEstoque();
            setInterval(atualizarVendas, 30000);
            setInterval(atualizarEstoque, 10000);
            
            // Preencher formulário se estiver editando
            <?php if(isset($produto_editar)): ?>
                abrirModalProduto();
                document.getElementById('modalProdutoTitulo').textContent = 'Editar Produto';
                document.getElementById('id_produto').value = '<?= $produto_editar['id_produto'] ?>';
                document.getElementById('referencia').value = '<?= $produto_editar['referencia_interna'] ?>';
                document.getElementById('nome').value = '<?= $produto_editar['nome_produto'] ?>';
                document.getElementById('descricao').value = '<?= $produto_editar['descricao'] ?? '' ?>';
                document.getElementById('preco').value = '<?= $produto_editar['preco_venda'] ?>';
                document.getElementById('estoque').value = '<?= $produto_editar['estoque_atual'] ?>';
                document.getElementById('codigo_barras').value = '<?= $produto_editar['codigo_barras_ean'] ?? '' ?>';
                document.getElementById('unidade_medida').value = '<?= $produto_editar['unidade_medida'] ?>';
                document.getElementById('ncm').value = '<?= $produto_editar['ncm'] ?? '01012100' ?>';
                document.getElementById('ibpt').value = '<?= $produto_editar['ibpt'] ?? '4.20' ?>';
                document.getElementById('acao_produto').name = 'editar_produto';

                <?php if(!empty($produto_editar['foto_produto'])): ?>
                    document.getElementById('imagemPreviewContainer').style.display = 'block';
                    document.getElementById('imagemPreview').src = 'uploads/imagens/<?= $produto_editar['foto_produto'] ?>';
                <?php endif; ?>
            <?php endif; ?>
            
            // Abrir modal de cliente se estiver editando
            <?php if(isset($cliente_editar)): ?>
                abrirModalCliente();
            <?php endif; ?>
            
            // Abrir modal de relatório se houver dados
            <?php if(!empty($relatorio_data)): ?>
                abrirModalRelatorio();
            <?php endif; ?>
        });

        // Funções para abrir/fechar modais
        function abrirModalProduto() {
    // Resetar o formulário antes de abrir
    document.getElementById('formProduto').reset();
    document.getElementById('modalProdutoTitulo').textContent = 'Cadastrar Produto';
    document.getElementById('acao_produto').name = 'cadastro_produto';
    document.getElementById('id_produto').value = '';
    document.getElementById('imagemPreviewContainer').style.display = 'none';
    
    // Abrir o modal
    document.getElementById('modalProduto').style.display = 'flex';
}

        function fecharModalProduto() {
    // Resetar o formulário
    document.getElementById('formProduto').reset();
    
    // Resetar o título e modo do modal
    document.getElementById('modalProdutoTitulo').textContent = 'Cadastrar Produto';
    document.getElementById('acao_produto').name = 'cadastro_produto';
    document.getElementById('id_produto').value = '';
    
    // Esconder a pré-visualização da imagem
    document.getElementById('imagemPreviewContainer').style.display = 'none';
    
    // Fechar o modal
    document.getElementById('modalProduto').style.display = 'none';
    
    // Remover o parâmetro de edição da URL
    if (window.location.search.includes('editar_produto')) {
        const url = new URL(window.location);
        url.searchParams.delete('editar_produto');
        window.history.replaceState({}, '', url);
    }
}

        function abrirModalCliente() {
            document.getElementById('modalCliente').style.display = 'flex';
        }

        function fecharModalCliente() {
            document.getElementById('modalCliente').style.display = 'none';
        }
        
        function abrirModalPeriodo() {
            document.getElementById('modalPeriodo').style.display = 'flex';
        }
        
        function fecharModalPeriodo() {
            document.getElementById('modalPeriodo').style.display = 'none';
        }
        
        function abrirModalRelatorio() {
            document.getElementById('modalRelatorio').style.display = 'flex';
        }
        
        function fecharModalRelatorio() {
            document.getElementById('modalRelatorio').style.display = 'none';
        }
        
        function imprimirRelatorio() {
            window.print();
        }
        
        // Funções de edição
        function editarProduto(id) {
            window.location.href = `dashboard.php?secao=produtos&editar_produto=${id}`;
        }
        
        function editarCliente(id) {
            window.location.href = `dashboard.php?secao=clientes&editar_cliente=${id}`;
        }
        
        // Funções de confirmação para exclusão
        function confirmarExclusao(id, tipo) {
            idParaExcluir = id;
            tipoExclusao = tipo;
            document.getElementById('mensagemConfirmacao').textContent = `Tem certeza que deseja excluir este ${tipo}?`;
            document.getElementById('modalConfirmacao').style.display = 'flex';
        }
        
        function confirmarAcao() {
            acaoConfirmada = true;
            fecharModalConfirmacao();
            
            if (tipoExclusao === 'produto') {
                window.location.href = `dashboard.php?secao=produtos&excluir_produto=${idParaExcluir}`;
            } else if (tipoExclusao === 'cliente') {
                window.location.href = `dashboard.php?secao=clientes&excluir_cliente=${idParaExcluir}`;
            }
        }
        
        function fecharModalConfirmacao() {
            if (!acaoConfirmada) {
                idParaExcluir = null;
                tipoExclusao = null;
            }
            document.getElementById('modalConfirmacao').style.display = 'none';
        }
        
        // Gerar relatório rápido
        function gerarRelatorioRapido(tipo) {
            const hoje = new Date();
            let dataInicio, dataFim;
            
            switch(tipo) {
                case 'hoje':
                    dataInicio = dataFim = formatarData(hoje);
                    break;
                case 'semana':
                    dataInicio = new Date(hoje);
                    dataInicio.setDate(hoje.getDate() - 7);
                    dataInicio = formatarData(dataInicio);
                    dataFim = formatarData(hoje);
                    break;
                case 'mes':
                    dataInicio = formatarData(new Date(hoje.getFullYear(), hoje.getMonth(), 1));
                    dataFim = formatarData(new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0));
                    break;
                default:
                    console.error('Tipo de relatório não reconhecido');
                    return;
            }
            
            document.getElementById('data_inicio').value = dataInicio;
            document.getElementById('data_fim').value = dataFim;
            document.getElementById('formPeriodo').submit();
        }
        
        function formatarData(data) {
            return data.toISOString().split('T')[0];
        }

        // Atualizar estoque em tempo real
        function atualizarEstoque() {
            fetch('atualizar_estoque.php')
                .then(response => {
                    if (!response.ok) throw new Error('Erro na rede');
                    return response.json();
                })
                .then(data => {
                    if (data.estoqueAtualizado) {
                        const rows = document.querySelectorAll('.dados-table tbody tr');
                        rows.forEach(row => {
                            const idProduto = row.querySelector('button[onclick^="editarProduto"]')
                                .getAttribute('onclick')
                                .match(/\d+/)[0];
                            
                            if (data.produtos[idProduto]) {
                                const estoqueCell = row.querySelector('td:nth-child(5)');
                                if (estoqueCell) {
                                    estoqueCell.textContent = parseFloat(data.produtos[idProduto].estoque).toFixed(3).replace('.', ',');
                                }
                            }
                        });
                    }
                })
                .catch(error => console.error('Erro ao atualizar estoque:', error));
        }

        // Atualizar vendas periodicamente
        function atualizarVendas() {
            fetch('atualizar_vendas.php')
                .then(response => {
                    if (!response.ok) throw new Error('Erro na rede');
                    return response.json();
                })
                .then(data => {
                    if (data.total_vendas !== undefined) {
                        const salesCard = document.querySelector('.card.sales');
                        if (salesCard) {
                            salesCard.querySelector('.value').textContent = 'R$ ' + data.total_vendas.toFixed(2).replace('.', ',');
                            salesCard.querySelector('.desc').textContent = 'Pedidos: ' + data.total_pedidos;
                            
                            const trendElement = salesCard.querySelector('.trend');
                            if (trendElement) {
                                if (data.total_pedidos > 0) {
                                    trendElement.innerHTML = '<i class="fas fa-arrow-up"></i> Hoje';
                                } else {
                                    trendElement.innerHTML = 
                                        data.caixa_aberto ? '<i class="fas fa-clock"></i> Sem vendas hoje' : '<i class="fas fa-clock"></i> Caixa fechado';
                                }
                            }
                        }
                    }
                })
                .catch(error => console.error('Erro ao atualizar vendas:', error));
        }

        // Validação do formulário de produto
        document.addEventListener('DOMContentLoaded', function() {
            const formProduto = document.getElementById('formProduto');
            if (formProduto) {
                formProduto.addEventListener('submit', function(e) {
                    const preco = parseFloat(document.getElementById('preco').value);
                    const estoque = parseFloat(document.getElementById('estoque').value);
                    const ncm = document.getElementById('ncm').value.trim();
                    const ibpt = parseFloat(document.getElementById('ibpt').value);
                    const nome = document.getElementById('nome').value.trim();
                    const referencia = document.getElementById('referencia').value.trim();

                    if (isNaN(preco) || preco <= 0) {
                        alert('O preço deve ser maior que zero');
                        e.preventDefault();
                        return;
                    }

                    if (isNaN(estoque) || estoque < 0) {
                        alert('O estoque não pode ser negativo');
                        e.preventDefault();
                        return;
                    }

                    if (nome === '') {
                        alert('O nome do produto é obrigatório');
                        e.preventDefault();
                        return;
                    }

                    if (referencia === '') {
                        alert('O código de referência é obrigatório');
                        e.preventDefault();
                        return;
                    }

                    if (ncm === '') {
                        alert('O NCM é obrigatório');
                        e.preventDefault();
                        return;
                    }

                    if (isNaN(ibpt) || ibpt < 0) {
                        alert('A alíquota IBPT deve ser um número positivo');
                        e.preventDefault();
                        return;
                    }
                });
            }
        });
    </script>
</body>
</html>

