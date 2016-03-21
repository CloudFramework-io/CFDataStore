<?php
    namespace CloudFramework\Service\DataStore\Message;

    use CloudFramework\Patterns\Singleton;

    abstract class Schema extends Singleton
    {
        public $id_int_index;

        /**
         * Required method that define the specific table to manipulate datastore(kind)
         * @return mixed
         */
        abstract function getKind();

        public function __construct($id = NULL)
        {
            if (NULL === $id) {
                $id = microtime(TRUE) * 10000;
            }
            $this->id_int_index = $id;
        }

        private function createEntity()
        {
            $entity = new \Google_Service_Datastore_Entity();
            $entity->setKey($this->createKeyForEntity());
            $property_map = [];
            $property_map = $this->hydrateProperties($property_map);
            $entity->setProperties($property_map);

            return $entity;
        }

        private function createKeyForEntity()
        {
            $path = new \Google_Service_Datastore_KeyPathElement();
            $path->setKind($this->getKind());
            $path->setName(get_class($this) . "[" . $this->id_int_index . "]");
            $key = new \Google_Service_Datastore_Key();
            $key->setPath([$path]);

            return $key;
        }

        /**
         * @return \Google_Service_Datastore_CommitRequest
         */
        public function createRequestMessage()
        {
            $entity = $this->createEntity();
            $mutation = new \Google_Service_Datastore_Mutation();
            $mutation->setUpsert([$entity]);
            $req = new \Google_Service_Datastore_CommitRequest();
            $req->setMode('NON_TRANSACTIONAL');
            $req->setMutation($mutation);

            return $req;
        }

        /**
         * Mapper for types of fields
         *
         * @param string $type
         * @param mixed $value
         * @param string $field
         * @param string $index
         *
         * @return \Google_Service_Datastore_Property
         */
        private function mapProperty($type, $value, $field, $index = '')
        {
            $property = new \Google_Service_Datastore_Property();
            switch (strtolower($type)) {
                default:
                case 'string':
                    $property->setStringValue($value);
                    break;
                case 'int':
                case 'integer':
                    //FIXME force intval when version was migrated to 64bit version
                    $property->setIntegerValue($value);
                    break;
                case 'datetime':
                    if (!$value instanceof \DateTime) {
                        syslog(LOG_INFO, "$field is not a DateTime object");
                        continue;
                    }
                    $property->setDateTimeValue($value->format(\DateTime::ATOM));
                    break;
                case 'float':
                    //FIXME force intval when version was migrated to 64bit version
                    $property->setDoubleValue($value);
                    break;
                case 'boolean':
                case 'bool':
                    $property->setBooleanValue(boolval($value ?: FALSE));
                    break;
            }
            if ('index' === strtolower($index)) {
                $property->setIndexed(TRUE);
            } else {
                $property->setIndexed(FALSE);
            }

            return $property;
        }

        /**
         * Mapper filters for types of fields
         *
         * @param string $type
         * @param mixed $value
         * @param string $field
         * @param string $operator
         *
         * @return string
         */
        private function mapFilter($type, $value, $field, $operator = '=')
        {
            $filter = '';
            switch (strtolower($type)) {
                default:
                case 'string':
                    $filter = "{$field} contains '{$value}'";
                    break;
                case 'int':
                case 'integer':
                case 'float':
                    $filter = "{$field} {$operator} {$value}";
                    break;
                case 'datetime':
                    if (!$value instanceof \DateTime) {
                        syslog(LOG_INFO, "$field is not a DateTime object");
                        continue;
                    }
                    $dateFilter = $value->format('Y-m-d');
                    $filter = "{$field} contains '{$dateFilter}'";
                    break;
                case 'boolean':
                case 'bool':
                    $boolVal = $value ? "true" : "false";
                    $filter = "{$field} = {$boolVal}";
                    break;
            }

            return $filter;
        }

        /**
         * @param array $property_map
         *
         * @return mixed
         */
        private function hydrateProperties(array &$property_map)
        {
            foreach (get_object_vars($this) as $key => $value) {
                list($field, $type, $index) = explode('_', $key, 3);
                if (!in_array($field, ['loaded', 'loadTs', 'loadMem'])) {
                    $property_map[$field] = $this->mapProperty($type, $value, $field, $index);
                }
            }

            return $property_map;
        }

        /**
         * @return array
         */
        public function generateFilteredQuery()
        {
            $filters = array();
            foreach (get_object_vars($this) as $key => $value) {
                list($field, $type, $index) = explode('_', $key, 3);
                if (!in_array($field, ['id', 'loaded', 'loadTs', 'loadMem']) && 'index' === $index && null !== $value) {
                    $filters[] = $this->mapFilter($type, $value, $field);
                }
            }

            return $filters;
        }

        /**
         * Get an unique hash for current class
         * @return string
         */
        public function generateHash()
        {
            $classFingerprint = '';
            foreach (get_object_vars($this) as $key => $value) {
                list($field, $type, $index) = explode('_', $key, 3);
                if (!in_array($field, ['id', 'loaded', 'loadTs', 'loadMem'])) {
                    $classFingerprint .= $field;
                    $classFingerprint .= $type ?: 'string';
                    $classFingerprint .= $index ?: '';
                    if ($value instanceof \DateTime) {
                        $classFingerprint .= $value->format(\DateTime::ATOM);
                    } else {
                        $classFingerprint .= $value ?: '';
                    }
                    $classFingerprint .= '_';
                }
            }

            return sha1($this->getKind() . $classFingerprint);
        }

        /**
         * Checks if field value is an ts field
         * @param $field
         *
         * @return bool
         */
        private function isDateTimeField($field) {
            try {
                $canTranslateToTime = (!is_string($field)) ? false : strtotime($field);
                $haveEnoughtLenght = (!is_string($field)) ? false : 24 === strlen($field);
            } catch(\Exception $e) {
                $canTranslateToTime = false;
                $haveEnoughtLenght = false;
            }
            return $canTranslateToTime && $haveEnoughtLenght;
        }

        /**
         * @param \Google_Service_Datastore_Entity $entity
         */
        public function hydrateFromEntity(\Google_Service_Datastore_Entity $entity)
        {
            $keys = json_decode(json_encode($entity->toSimpleObject()->key), true);
            $class = get_class($this);
            $schemaEntity = null;
            if(!empty($keys)) {
                try {
                    $_id = $keys['path'][0]['name'];
                } catch(\Exception $e) {
                    $_id = microtrime(true) * 10000;
                }
                preg_match('/^(.*)\[(.*)\]$/', $_id, $ids);
                if(array_key_exists(2, $ids)) {
                    $id = $ids[2];
                } else {
                    $id = $_id;
                }
            } else {
                $id = microtrime(true) * 10000;
            }
            $schemaEntity = new $class($id);
            $schemaEntity->id_int_index = $id;

            $array = json_decode(json_encode($entity->toSimpleObject()->properties), true);
            //Hydrate the rest of parameters
            if (null !== $schemaEntity) {
                foreach (get_object_vars($this) as $key => $value) {
                    list($field, $type, $index) = explode('_', $key, 3);
                    try {
                        if (array_key_exists($field, $array) && $field !== 'id') {
                            $values = array_values($array[$field]);
                            if(array_key_exists(0, $values) && null !== $values[0]) {
                                if($this->isDateTimeField($values[0])) {
                                    $valueTs = new \DateTime(date(DATE_ATOM, strtotime($values[0])));
                                    $schemaEntity->$key = (null !== $valueTs) ? $valueTs->format(\DateTime::ATOM) : $values[0];
                                } else {
                                    $schemaEntity->$key = $values[0];
                                }
                            } else {
                                $schemaEntity->$key = null;
                            }
                        }
                    } catch(\Exception $e) {
                        $schemaEntity->$key = null;
                    }
                }
            }

            return $schemaEntity;
        }

        /**
         * Export Schema Object to plain array or stdObject
         * @param bool|TRUE $array
         *
         * @return array|string
         */
        public function export($array = true)
        {
            $data = array();
            foreach (get_object_vars($this) as $key => $value) {
                list($field, $type, $index) = explode('_', $key, 3);
                if (!in_array($field, ['loaded', 'loadTs', 'loadMem'])) {
                    try {
                        if($this->isDateTimeField($value)) {
                            $valueTs = new \DateTime(date(DATE_ATOM, strtotime($value)));
                            $data[$field] = (null !== $valueTs) ? $valueTs->format(\DateTime::ATOM) : $value;
                        } else {
                            $data[$field] = $value;
                        }
                    } catch(\Exception $e) {
                        $data[$field] = $value;
                    }
                }
            }
            return ($array) ? $data : json_encode($data);
        }

        /**
         * Compare if one field exists
         * @param string $field
         * @param bool $checkFilter
         *
         * @return bool
         */
        public function fieldExists($comparedField, $checkFilter = false)
        {
            $exists = false;
            foreach (get_object_vars($this) as $key => $value) {
                list($field, $type, $index) = explode('_', $key, 3);
                if (!in_array($field, ['loaded', 'loadTs', 'loadMem'])) {
                    if($comparedField === $field) {
                        $exists = ($checkFilter) ? !empty($value) : true;
                        break;
                    }
                }
            }
            return $exists;
        }

    }
