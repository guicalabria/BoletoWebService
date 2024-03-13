<?php
namespace Plotag\Enter8;

/**
 *  Class responsible for communication with Branco do Brasil WebServices
 *
 * @category  library
 * @package   BoletoWebService
 * @license   https://opensource.org/licenses/MIT MIT
 * @author    Reginaldo Coimbra Vieira < recovieira@gmail.com >
 * @link      https://github.com/recovieira/bbboletowebservice.git for the canonical source repository
 */


class BancodoBrasilWeb {

	const AMBIENTE_PRODUCAO = 1;
	const AMBIENTE_TESTE = 2;

	static private $_urls = array(
		self::AMBIENTE_PRODUCAO => array(
			// URL para obtenção da token para registro de boletos (produção)
			'token' => 'https://oauth.bb.com.br/oauth/token',
			// URL para registro de boleto (produção)
			'registro' => 'https://cobranca.bb.com.br:7101/registrarBoleto'
		),
		self::AMBIENTE_TESTE => array(
			// URL para obtenção da token para testes
			'token' => 'https://oauth.hm.bb.com.br/oauth/token',
			// URL para registro de boleto para teste
			'registro' => 'https://cobranca.homologa.bb.com.br:7101/registrarBoleto'
		)
		);

	private $_clientID;
	private $_secret;

	// Ambiente do sistema: teste ou produção?
	private $_ambiente;

	// Tempo limite para obter resposta de 20 segundos
	private $_timeout = 20;

	// Tempo em segundos válido da token gerada pelo BB
	static private $_ttl_token = 1200;
	// Porcentagem tolerável antes de tentar renovar a token (0 a 100). Se ultrapassar, tente renová-la automaticamente. // 0 (zero) -> sempre renova
	// 100 -> tenta usá-la até o final do tempo
	static private $_porcentagemtoleravel_ttl_token = 80;

  	// Caminho da pasta para salvar arquivos de cache
	static private $_caminhoPastaCache_estatico = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'cache';
	private $_caminhoPastaCache;


	// Armazena informação sobre o erro ocorrido
	public $erro = false;
	public $mensagem = '';

	// Armazena a última token processada pelo método obterToken()
	private $_tokenEmCache;

	/**
  	 * Construtor do Consumidor de WebService do BB
  	 *
  	 * @param array $params Parâmetros iniciais para construção do objeto
  	 * @throws Exception Quando o banco não é suportado
  	 */
	public function __construct(array $params)
	{
		if ( !isset($params['webserviceid']) || $params['webserviceid']=='' )
		{
			throw new \Exception('Client ID não configurado');
		}
		if ( !isset($params['webservicesecret']) || $params['webservicesecret']=='' )
		{
			throw new \Exception('Client Secrect não configurado');
		}


		if ( isset($params['arquivoteste']) && (int)$params['arquivoteste']===1  ) $this->alterarParaAmbienteDeTestes();
		else $this->alterarParaAmbienteDeProducao();

			// Usar, por padrão, o caminho definido no atributo estático "_caminhoPastaCache_estatico"
			if ( isset($params['tokendir']) && $params['tokendir']!='' ) $this->_caminhoPastaCache = $params['tokendir'];
			else $this->_caminhoPastaCache = self::$_caminhoPastaCache_estatico;

			$this->_clientID	=& $params['webserviceid'];
			$this->_secret		=& $params['webservicesecret'];
	}

	/**
	 * Alterar para o ambiente de produção
	 */
	public function alterarParaAmbienteDeProducao()
	{
		$this->_ambiente = self::AMBIENTE_PRODUCAO;
	}

	/**
	 * Alterar para o ambiente de testes
	 */
	public function alterarParaAmbienteDeTestes()
	{
		$this->_ambiente = self::AMBIENTE_TESTE;
	}

	/**
	 * Alterar o tempo máximo para aguardar resposta
	 * @param int $timeout	Tempo > 0 (em segundos) para aguardar resposta
	 */
	public function alterarLimiteDeResposta($timeout)
	{
		$this->_timeout =& $timeout;
	}

	/**
	 * Alterar o caminho da pasta usada para cache
	 * @param string $novocaminho	Novo caminho
	 * @param bool $usaremnovasinstancias	Usar o novo caminho em instâncias futuras?
	 */
	public function trocarCaminhoDaPastaDeCache($novocaminho, $usaremnovasinstancias = false)
	{
		$this->_caminhoPastaCache =& $novocaminho;

		if ($usaremnovasinstancias) 	self::$_caminhoPastaCache_estatico =& $novocaminho;
	}

	/**
	 * Inicia as configurações do Curl útil para
	 * realizar as requisições de token e registro de boleto
	 * @returns resource Curl pré-configurado
	 */
	private function _prepararCurl()
	{
		$curl = curl_init();
		curl_setopt_array($curl, array(
			//CURLOPT_BINARYTRANSFER => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_POST => true,
			CURLOPT_TIMEOUT => $this->_timeout,
			CURLOPT_MAXREDIRS => 3
		));
		return $curl;
	}

	/**
  	 * Inicia as configurações do Curl útil para
  	 * realizar as requisições de token e registro de boleto
  	 * @param bool $naousarcache		Especifica se o programador aceita ou não obter uma token já salva em cache
  	 * @returns object|bool Objeto, caso o token foi recebido com êxito, ou false, caso contrário
  	 */
	public function obterToken($naousarcache = true)
	{
			if ($this->_tokenEmCache && !$naousarcache) return $this->_tokenEmCache;

			// Cria pasta para cache, caso ela ainda não exista
			if ( !is_dir($this->_caminhoPastaCache) )
			{
				if ( !mkdir($this->_caminhoPastaCache, 0775, true) )
				{
					$this->erro = true;
					$this->mensagem .= 'Erro ao gerar diretório cache';
					return false;
				}
			}

			// Define o caminho para o arquivo de cache
			$caminhodoarquivodecache = $this->_caminhoPastaCache . DIRECTORY_SEPARATOR . 'bb_token_cache.php';

			if (!$naousarcache)
			{
					// Se o arquivo existir, retorna o timestamp da última modificação. Se não, retorna "false"
					$timedamodificacao = @filemtime($caminhodoarquivodecache);

					// Testa se o arquivo existe e se o seu conteúdo (token) foi modificado dentro do tempo tolerável
					if ($timedamodificacao && $timedamodificacao + self::$_ttl_token * self::$_porcentagemtoleravel_ttl_token / 100 > time())
					{
						// Tenta abrir o arquivo para leitura e escrita
						$arquivo = @fopen($caminhodoarquivodecache, 'c+');

						// Se conseguir-se abrir o arquivo...
						if ($arquivo)
						{
								// trava-o para escrita enquanto os dados são lidos
								flock($arquivo, LOCK_SH);

								// Lê o conteúdo do arquivo
								$dados = '';
								do
									$dados .= fread($arquivo, 1024);
								while (!feof($arquivo));

								fclose($arquivo);

								// Retorna apenas a token salva no arquivo
								return $this->_tokenEmCache = (object) array(
									'token' => preg_replace("/^(.*\\n){4}'?|'?;?\\n*$/", '', $dados),
									'cache' => true
								);
						}
					}
			}

			$curl = $this->_prepararCurl();
			curl_setopt_array($curl, array(
				CURLOPT_URL => self::$_urls[$this->_ambiente]['token'],
				CURLOPT_POSTFIELDS => 'grant_type=client_credentials&scope=cobranca.registro-boletos',
				CURLOPT_HTTPHEADER => array(
					'Authorization: Basic ' . base64_encode($this->_clientID . ':' . $this->_secret),
					'Cache-Control: no-cache'
				)
			));

			$resposta = curl_exec($curl);
			curl_close($curl);

			// Recebe os dados do WebService no formato JSON.
			// Realiza o parse da resposta e retorna.
			// Caso seja um valor vazio ou fora do formato, retorna false.
			$resultado = json_decode($resposta);

			// Se o valor salvo em "$resultado" for um objeto e se existir o atributo "access_token" nele...
			if ($resultado)
			{
				if (isset($resultado->access_token))
				{
					// Armazena token em cache apenas se a porcentagem tolerável sobre o tempo da token for superior a 0%
					if (self::$_porcentagemtoleravel_ttl_token > 0)
					{
						// Tenta abrir o arquivo para leitura e escrita
						$arquivo = @fopen($caminhodoarquivodecache, 'c+');

						// Se conseguir-se abrir o arquivo...
						if ($arquivo) {
							// trava-o para leitura e escrita
							flock($arquivo, LOCK_EX);

							// apaga todo o seu conteúdo
							ftruncate($arquivo, 0);

							// escreve a token no arquivo
							fwrite($arquivo, "<?php\nheader('Status: 403 Forbidden', true, 403);\nheader('Content-Type: text/plain');\ndie('Access denied');\n'" . $resultado->access_token . "';\n");

							fclose($arquivo);
						}
					}

					return $this->_tokenEmCache = (object) array('token' => &$resultado->access_token,'cache' => false);
				}
				else
				{
					$this->erro = true;
					$this->mensagem = isset($resultado->error_description) ? utf8_decode($resultado->error_description): 'Erro inesperado na resposta do Banco do Brasil';
					return false;
				}
			}
		else
		{
			$this->erro = true;
			$this->mensagem .= 'Não foi possível conectar-se ao Banco do Brasil';
			return false;
		}
		return false;
	}

	/**
	 * Passa por todos os nós do XML e retorna no formato de array
	 * considerando apenas o valor do nó (nodeValue) e o nome do
	 * nó (nodeName sem namespace)
	 * @param DOMNode $no		Nó a ser percorrido pela função
	 * @param Array &$resultado	Variável que deverá armazenar o resultado encontrado
	 * @returns array Transcrição do formato XML em array
	 */
	static private function _converterNosXMLEmArray($no, &$resultado)
	{
			if ($no->firstChild && $no->firstChild->nodeType == XML_ELEMENT_NODE)
			{
					foreach ($no->childNodes as $pos)
					{
							self::_converterNosXMLEmArray($pos, $resultado[$pos->localName]);
					}
			}
			else $resultado = html_entity_decode(trim($no->nodeValue));
	}

	/**
	 * Recebe um array contendo o mapeamento "campo WSDL" -> "valor", conforme
	 * descrito na página 18 e 19 da especificação do WebService, realiza a chamada
	 * e retorna o resultado do Banco do Brasil no formato array ao invés de XML.
	 * @param array $data	Array com mapeamento nome -> valor conforme descrito na página 18 e 19 da especificação (vide)
	 * @param string $token Token recebida após requisição ao método "obterToken". Se não for informada, o método o obtém automaticamente. O método prioriza uma token já obtida e salva em cache, mas se ela já expirou, ele tenta renová-la automaticamente. Não é parâmetro obrigatório. Se for informada, o método apenas tenta registrar o boleto a usando. Se a token já expirou, ele não tenta renová-la automaticamente.
	 * @returns array|bool Transcrição da resposta do WebService em array ou "false" em caso de falha
	 */
	public function registrarBoleto($parametros, $token = false)
	{
			$tokeninformada = (bool) $token;
			$forcarobtertoken = false;

			// Montar envelope contendo a requisição do serviço
			$requisicao = '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsd="http://www.tibco.com/schemas/bws_registro_cbr/Recursos/XSD/Schema.xsd"><SOAP-ENV:Body><xsd:requisicao>';

			// Coloca cada parâmetro na requisição
			foreach ($parametros as $no => &$valor)
				$requisicao .= "<xsd:$no>" . htmlspecialchars($valor) . "</xsd:$no>";

			// Fecha o nó da requisição, o corpo da mensagem e o envelope
			$requisicao .= '</xsd:requisicao></SOAP-ENV:Body></SOAP-ENV:Envelope>';

			for (;;)
			{
				// Se uma token não for informada, tenta obter a token do cache ou do Banco do Brasil, se ainda não existir nenhuma token salva no cache
				if (!$tokeninformada || $forcarobtertoken)
				{
					// Na primeira tentativa, tenta obter a token do cache. Se ela não for válida, força a obtenção de uma nova token na segunda execução quando "$forcarobtertoken" for true
					$token = $this->obterToken($forcarobtertoken);

					// Se der qualquer error em obter a token, retorna "false"
					if ( !$token ) { $this->erro=true;$this->menagem .= 'Erro ao obter a token do Banco do Brasil - ' ;return false; }

					// Se a token foi obtida diretamente do BB e não do cache, não precisa repetir o laço para obter nova token
					if ( !$token->cache ) $forcarobtertoken = true;

					$token =& $token->token;
				}
				file_put_contents(DIREMPRESA.'Temporaria/reqDup'.$parametros['textoNumeroTituloBeneficiario'].'.xml',$requisicao);

				// Preparar requisição
				$curl = $this->_prepararCurl();
					curl_setopt_array($curl, array(
					CURLOPT_URL => self::$_urls[$this->_ambiente]['registro'],
					CURLOPT_POSTFIELDS => &$requisicao,
					CURLOPT_HTTPHEADER => array(
						'Content-Type: text/xml;charset=UTF-8',
						"Authorization: Bearer $token",
						'SOAPAction: registrarBoleto'
					)
				));
				$resposta = curl_exec($curl);
				curl_close($curl);

				if ( $resposta )
				{
					// Criar documento XML para percorrer os nós da resposta
					$dom = new \DOMDocument('1.0', 'UTF-8');
					// Verificar se o formato recebido é um XML válido.
					// A expressão regular executada por "preg_replace" retira espaços vazios entre tags.
					if ( @$dom->loadXML(preg_replace('/(?<=>)\\s+(?=<)/', '', $resposta)) )
					{
						file_put_contents(DIREMPRESA.'Temporaria/resDup'.$parametros['textoNumeroTituloBeneficiario'].'.xml',$resposta);
						// Realiza o "parse" da resposta a partir do primeiro nó no
						// corpo do documento dentro do envelope
						$resultado = array();
						self::_converterNosXMLEmArray($dom->documentElement->firstChild->firstChild, $resultado);
					}
					else $resultado = false;
				}
				else
				{
					$this->erro = true;
					$this->mensagem = 'Não foi possível conectar-se ao Banco do Brasil';
					return false;
				}

				// Se ocorreu tudo bem, sai
				if (is_array($resultado) && array_key_exists('codigoRetornoPrograma',$resultado) && $resultado['codigoRetornoPrograma']==0) return $resultado;

				// Além de sair se um erro diferente da token for identificado, encerra o loop se uma token for informada diretamente para o método ou se o laço já executou duas vezes, sendo a segunda forçando a obtenção de nova token. Esta condição também é desviada quando a token já expirou. Portanto, o laço será repetido novamente, porém renovando a token na segunda tentativa.
				if (!$resultado || is_array($resultado) && array_key_exists('textoMensagemErro', $resultado) || $forcarobtertoken || $tokeninformada)
				{
					$this->erro = true;
					if ( is_array($resultado) )
					{
						if ( isset($resultado['detail']) && isset($resultado['detail']['erro']) )
						{
							$this->mensagem .= '<span class="formok">Erro resposta do Banco do Brasil</span>';
							foreach($resultado['detail']['erro'] as $itemerro)
							{
								$this->mensagem .= $itemerro;
							}
						}
						else $this->mensagem = (isset($resultado['textoMensagemErro']) ? $resultado['textoMensagemErro'] : 'Erro inesperado na resposta do Banco do Brasil');
					}
					else $this->mensagem = 'Erro inesperado na resposta do Banco do Brasil';

					// Retorna "false" em caso de falha
					return false;
				}

				// Força a obtenção de nova token e executa o laço apenas mais uma vez
				$forcarobtertoken = true;
			}
	}
}
