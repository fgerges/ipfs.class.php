<?php

// https://docs.ipfs.io/reference/http/api/

namespace eth_sign;

class IPFS_Server {
    private $IP;
    private $port;
    private $API_port;
    private $https;

    public function __construct($ip, $port, $api, $https) {
        $this->IP = $ip;
        $this->port = $port;
        $this->API_port = $api;
        $this->https = $https;
    }

    public function getURL($uri, $api) {
        $url = 'http' . ($this->https ? 's' : '') . '://' . $this->IP . ':' . ($api ? $this->API_port : $this->port) . '/';
        if ($api) {
            $url .= 'api/v0/';
        }
        return $url . $uri;
    }
}

class IPFS {
    private $server;

    /**
     * Valeurs de port et api par défaut issus de js-api servi par nodejs
     * 
     */
    public function __construct($ip = '127.0.0.1', $port = '8080', $api = '5001', $https = false) {
        $this->server = new IPFS_Server($ip, $port, $api, $https);
    }

    private function send($uri, $api = false, $data = null, $folder = '', $makeDir = false) {
        $url = $this->server->getURL($uri, $api);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, false);
        if($makeDir) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Disposition: form-data; name="file"; filename="' . $folder . '"',
                'Content-Type: application/x-directory'
            ));
        }

        if ($data !== null) {
            if (!is_array($data)) {
                $data = [$data];
            }
            $post_fields = [];
            foreach ($data as $offset => $filepath) {
                $filedest = ($folder != '' ? $folder . '%2F' : '') . basename($filepath);
                $cfile = curl_file_create($filepath, 'application/octet-stream', $filedest);
                $post_fields['file' . sprintf('%03d', $offset + 1)] = $cfile;
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        }

        $output = curl_exec($ch);

        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $code_category = substr($response_code, 0, 1);
        if ($code_category == '5' || $code_category == '4') {
            $dataOut = @json_decode($output, true);
            if (!$dataOut && json_last_error() != JSON_ERROR_NONE) {
                throw new \Exception("IPFS code retour $response_code : " . substr($output, 0, 200), $response_code);
            } else if (is_array($dataOut) && isset($dataOut['Code']) && isset($dataOut['Message'])) {
                throw new \Exception("IPFS Erreur {$dataOut['Code']} : {$dataOut['Message']}", $response_code);
            }
        }

        curl_close($ch);

        if ($output === false) {
            throw new \Exception('IPFS ne répond pas...', 1);
        } else if ($api) {
            $output = json_decode($output, true);
        }

        return $output;
    }

    private function sendFile($uri, $api = false, $filepath = null, $folder = '', $options = null, $multiple = false) {
        $query_string = $options!=null ? '?' . http_build_query($options) : '';
        $response = $this->send($uri . $query_string, $api, $filepath, $folder);
        if ($multiple) {
            $data = [];
            foreach (explode("\n", $response) as $json_data_line) {
                if (strlen(trim($json_data_line))) {
                    $data[] = json_decode($json_data_line, true);
                }
            }
        } else {
            $data = $response;
        }
        return $data;
    }

    /**
     * Récupère le contenu d'un hach
     * 
     * @param string $hash du contenu à récupérer
     * @return string le contenu correspondant
     */
    public function cat($hash) {
        return $this->send('ipfs/' . $hash);
    }

    /**
     * Ajoute un contenu textuel à IPFS
     * 
     * @param boolean $content contenu textuel envoyé
     * @return string le hash du contenu envoyé
     */
    public function addContent($content) {
        $req = $this->send('add?stream-channels=true', true, $content);
        return $req['Hash'];
    }

    /**
     * Ajoute un dossier directement à IPFS
     * 
     * @param string $folder nom du dossier à créer
     * @return  array retourne un tableau de la forme :
     * {
     *      "Name": "nom du dossier",
     *      "Hash": "hash renvoyé par IPFS"
     * }
     */
    public function makeFolder($folder) {
        return $this->send('add', true, null, $folder, null, true);
    }

    /**
     * Ajoute un fichier directement à IPFS
     * 
     * @param string $filepath chemin local du fichier à envoyer
     * @param string $folder dossier IPFS existant où écrire le fichier
     * @param boolean $pin épingle le fichier si true
     * @return  array retourne un tableau de la forme :
     * {
     *      "Name": "nom du fichier",
     *      "Hash": "hash renvoyé par IPFS",
     *      "Size": "taille du fichier"
     * }
     */
    public function addFile($filepath, $folder = '', $pin = true) {
        $options = [
            'pin' => $pin
        ];
        return $this->sendFile('add', true, $filepath, $folder, $options);
    }

    /**
     * Supprime un fichier
     * 
     * @param string $filename nom du fichier
     * @param string $folder nom du dossier contenant le fichier
     * @return  array retourne une réponse http de type text/plain
     */
    public function removeFile($filename, $folder = '') {
        $uri = 'files/rm?arg=' . ($folder != '' ? $folder . '%2F' : '') . $filename;
        return $this->send($uri, true);
    }

    /**
     * Ajoute un ou des fichiers dans un dossier à IPFS
     *
     * @param string|array $filepaths chemin du fichier à envoyer ou tableau si plusieurs
     * @param boolean $pin épingle le fichier si true
     * @return array retourne une structure de la forme :
     * {
     *     "files": [
     *         {
     *             "Name": "file1.ex1",
     *             "Hash": "...hash_value_1..."
     *         },
     *         {
     *             "Name": "file2.ex2",
     *             "Hash": "...hash_value_2..."
     *         }
     *     ],
     *     "FolderHash": "...hash_of_the_parent_folder..."
     * }
     */
    public function addFiles($filepaths, $pin = true) {
        $options = [
            'pin' => $pin,
            'w' => 'true'
        ];
        $responses = $this->sendFile('add', true, $filepaths, $options, true);
        $return_data = [
            'files' => [],
            'FolderHash' => '',
        ];
        $responses_count = count($responses);
        for ($i = 0; $i < $responses_count; $i++) {
            if ($i === $responses_count - 1) {
                $return_data['FolderHash'] = $responses[$i]['Hash'];
                continue;
            }
            $return_data['files'][$i] = $responses[$i];
        }
        return $return_data;
    }

    /**
     * Obtient la structure liée au hash
     * 
     * @param string $hash du noeud à inspecter
     * @return array retourne un tableau de structures de la forme :
     * [
     *      ['Hash', 'Size', 'Name'],
     *      ...
     * ]
     */
    public function ls($hash) {
        $data = $this->send('ls/' . $hash, true);
        return $data['Objects'][0]['Links'];
    }

    /**
     * Retourne la taille d'un noeud
     * 
     * @param string $hash du noeud à inspecter
     * @return int taille du noeud
     */
    public function size($hash) {
        $data = $this->send('object/stat/' . $hash, true);
        return $data['CumulativeSize'];
    }

    /**
     * Epingle un noeud
     * 
     * @param string $hash du noeud à épingler
     * @return boolean true si épinglé
     */
    public function pinAdd($hash) {
        return $this->send('pin/add/' . $hash, true);
    }

    /**
     * Supprime l'épingle d'un noeud
     * 
     * @param string $hash du noeud où supprimer l'épingle
     * @return boolean true si noeud épingle supprimée
     */
    public function pinRm($hash) {
        return $this->send('pin/rm/' . $hash, true);
    }

    /**
     * Retourne la version IPFS utilisée
     * 
     * @return string version IPFS
     */
    public function version() {
        $data = $this->send('version', true);
        return $data['Version'];
    }

    /**
     * Retourne l'identifiant utilisé
     * 
     * @return string identifiant client IPFS
     */
    public function id() {
        return $this->send('id', true);
    }
}
