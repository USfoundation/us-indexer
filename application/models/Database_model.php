<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

class Database_model extends CI_Model
{

    /*
     *
     * This model does basic CRUD (Create, Read,
     * Update & Delete) operations on Mench's
     * three main tables:
     *
     * - table_entities
     * - table_intents
     * - table_ledger
     *
     * Think of this as the most internal layer
     * input/output processor for our platform.
     *
     * Also updated content with Algolia which
     * is our third-party search engine.
     *
     * */

    function __construct()
    {
        parent::__construct();
    }


    function fn___en_create($insert_columns, $external_sync = false, $tr_miner_en_id = 0)
    {

        //What is required to create a new intent?
        if (fn___detect_missing_columns($insert_columns, array('en_status', 'en_name'))) {
            return false;
        }

        if (isset($insert_columns['en_metadata'])) {
            $insert_columns['en_metadata'] = serialize($insert_columns['en_metadata']);
        } else {
            $insert_columns['en_metadata'] = null;
        }

        if (!isset($insert_columns['en_trust_score'])) {
            //Will be later calculated via a cron job:
            $insert_columns['en_trust_score'] = 0;
        }

        //Lets now add:
        $this->db->insert('table_entities', $insert_columns);

        //Fetch inserted id:
        if (!isset($insert_columns['en_id'])) {
            $insert_columns['en_id'] = $this->db->insert_id();
        }

        if ($insert_columns['en_id'] > 0) {

            if ($external_sync) {

                //Update Algolia:
                $algolia_sync = $this->Database_model->fn___update_algolia('en', $insert_columns['en_id']);

                //Log transaction new entity:
                $this->Database_model->fn___tr_create(array(
                    'tr_miner_en_id' => ($tr_miner_en_id > 0 ? $tr_miner_en_id : $insert_columns['en_id']),
                    'tr_en_child_id' => $insert_columns['en_id'],
                    'tr_type_en_id' => 4251, //New Entity Created
                    'tr_metadata' => array(
                        'algolia_sync' => $algolia_sync,
                    ),
                ));

                //Fetch to return the complete entity data:
                $ens = $this->Database_model->fn___en_fetch(array(
                    'en_id' => $insert_columns['en_id'],
                ));

                return $ens[0];

            } else {

                //Return provided inputs plus the new entity ID:
                return $insert_columns;

            }

        } else {

            //Ooopsi, something went wrong!
            $this->Database_model->fn___tr_create(array(
                'tr_en_parent_id' => $tr_miner_en_id,
                'tr_content' => 'fn___en_create() failed to create a new entity',
                'tr_type_en_id' => 4246, //Platform Error
                'tr_metadata' => $insert_columns,
            ));
            return false;

        }
    }

    function fn___in_create($insert_columns, $external_sync = false, $tr_miner_en_id = 0)
    {

        //What is required to create a new intent?
        if (fn___detect_missing_columns($insert_columns, array('in_status', 'in_outcome'))) {
            return false;
        }

        if (isset($insert_columns['in_metadata']) && count($insert_columns['in_metadata']) > 0) {
            $insert_columns['in_metadata'] = serialize($insert_columns['in_metadata']);
        } else {
            $insert_columns['in_metadata'] = null;
        }

        //Lets now add:
        $this->db->insert('table_intents', $insert_columns);

        //Fetch inserted id:
        if (!isset($insert_columns['in_id'])) {
            $insert_columns['in_id'] = $this->db->insert_id();
        }

        if ($insert_columns['in_id'] > 0) {

            if ($external_sync) {

                //Update Algolia:
                $algolia_sync = $this->Database_model->fn___update_algolia('in', $insert_columns['in_id']);

                //Log transaction new entity:
                $this->Database_model->fn___tr_create(array(
                    'tr_miner_en_id' => $tr_miner_en_id,
                    'tr_in_child_id' => $insert_columns['in_id'],
                    'tr_type_en_id' => 4250, //New Intent Created
                    'tr_metadata' => array(
                        'algolia_sync' => $algolia_sync,
                    ),
                ));

                //Fetch to return the complete entity data:
                $ins = $this->Database_model->fn___in_fetch(array(
                    'in_id' => $insert_columns['in_id'],
                ));

                return $ins[0];

            } else {

                //Return provided inputs plus the new entity ID:
                return $insert_columns;

            }

        } else {

            //Ooopsi, something went wrong!
            $this->Database_model->fn___tr_create(array(
                'tr_en_parent_id' => $tr_miner_en_id,
                'tr_content' => 'fn___in_create() failed to create a new intent',
                'tr_type_en_id' => 4246, //Platform Error
                'tr_metadata' => $insert_columns,
            ));
            return false;

        }
    }

    function fn___tr_create($insert_columns, $external_sync = false)
    {

        if (fn___detect_missing_columns($insert_columns, array('tr_type_en_id'))) {
            return false;
        }

        //Unset un-allowed columns to be manually added:
        if (isset($insert_columns['tr_coins'])) {
            unset($insert_columns['tr_coins']);
        }

        //Clean metadata is provided:
        if (isset($insert_columns['tr_metadata'])) {
            $insert_columns['tr_metadata'] = serialize($insert_columns['tr_metadata']);
        } else {
            $insert_columns['tr_metadata'] = null;
        }

        //Try to auto detect user:
        if (!isset($insert_columns['tr_miner_en_id']) || is_null($insert_columns['tr_miner_en_id'])) {
            //Attempt to fetch creator ID from session:
            $entity_data = $this->session->userdata('user');
            if (isset($entity_data['en_id']) && intval($entity_data['en_id']) > 0) {
                $insert_columns['tr_miner_en_id'] = $entity_data['en_id'];
            } else {
                //Do not issue credit to any miner:
                $insert_columns['tr_miner_en_id'] = $this->config->item('en_platform_miner_id');
            }
        } elseif($insert_columns['tr_miner_en_id'] == 0){
            //Shortcut for developers who don't know the default mench ID
            $insert_columns['tr_miner_en_id'] = $this->config->item('en_platform_miner_id');
        }

        //Set some defaults:
        if (!isset($insert_columns['tr_content'])) {
            $insert_columns['tr_content'] = null;
        }

        if (!isset($insert_columns['tr_timestamp']) || is_null($insert_columns['tr_timestamp'])) {
            //Time with milliseconds:
            $t = microtime(true);
            $micro = sprintf("%06d", ($t - floor($t)) * 1000000);
            $d = new DateTime(date('Y-m-d H:i:s.' . $micro, $t));
            $insert_columns['tr_timestamp'] = $d->format("Y-m-d H:i:s.u");
        }

        if (!isset($insert_columns['tr_status'])|| is_null($insert_columns['tr_status'])) {
            $insert_columns['tr_status'] = 2; //Auto Published
        }

        //Set some zero defaults if not set:
        foreach (array('tr_in_child_id', 'tr_in_parent_id', 'tr_en_child_id', 'tr_en_parent_id', 'tr_tr_id') as $dz) {
            if (!isset($insert_columns[$dz])) {
                $insert_columns[$dz] = 0;
            }
        }


        //Does this transaction type award coins?
        if(in_array($insert_columns['tr_type_en_id'], $this->config->item('en_ids_4374'))){
            //Yes, issue coins:
            $en_all_4374 = $this->config->item('en_all_4374');
            $insert_columns['tr_coins'] = doubleval($en_all_4374[$insert_columns['tr_type_en_id']]['m_desc']);
        }

        //Lets log:
        $this->db->insert('table_ledger', $insert_columns);

        //Fetch inserted id:
        $insert_columns['tr_id'] = $this->db->insert_id();

        //All good huh?
        if ($insert_columns['tr_id'] < 1) {
            return false;
        }


        //Sync algolia?
        if ($external_sync) {

            if ($insert_columns['tr_en_parent_id'] > 0) {
                $algolia_sync = $this->Database_model->fn___update_algolia('en', $insert_columns['tr_en_parent_id']);
            }

            if ($insert_columns['tr_en_child_id'] > 0) {
                $algolia_sync = $this->Database_model->fn___update_algolia('en', $insert_columns['tr_en_child_id']);
            }

            if ($insert_columns['tr_in_parent_id'] > 0) {
                $algolia_sync = $this->Database_model->fn___update_algolia('in', $insert_columns['tr_in_parent_id']);
            }

            if ($insert_columns['tr_in_child_id'] > 0) {
                $algolia_sync = $this->Database_model->fn___update_algolia('in', $insert_columns['tr_in_child_id']);
            }



        }

        //Notify admins for certain subscriptions
        if(0){
            foreach ($this->config->item('notify_admins') as $subscription) {

                //Do not notify about own actions:
                if (in_array($insert_columns['tr_miner_en_id'], $subscription['admin_en_ids'])) {
                    continue;
                }

                //Does this transaction Match?
                if (in_array($insert_columns['tr_type_en_id'], $subscription['admin_notify'])) {

                    //Just do this one:
                    if (!isset($trs[0])) {
                        //Fetch Transaction Data:
                        $trs = $this->Database_model->fn___tr_fetch(array(
                            'tr_id' => $insert_columns['tr_id']
                        ));
                    }

                    //Did we find it? We should have:
                    if (isset($trs[0])) {

                        $subject = 'Notification: ' . trim(strip_tags($trs[0]['in_outcome'])) . ' - ' . (isset($trs[0]['en_name']) ? $trs[0]['en_name'] : 'System');

                        //Compose email:
                        $html_message = null; //Start

                        if (strlen($trs[0]['tr_content']) > 0) {
                            $html_message .= '<div>' . fn___echo_link(nl2br($trs[0]['tr_content'])) . '</div><br />';
                        }

                        //Lets go through all references to see what is there:
                        foreach ($this->config->item('ledger_filters') as $tr_field => $obj_type) {
                            if (intval($trs[0][$tr_field]) > 0) {
                                //Yes we have a value here:
                                $html_message .= '<div>' . ucwrods(str_replace('tr', 'Transaction', str_replace('en', 'Entity', str_replace('in', 'Intent', str_replace('_', ' ', str_replace('tr_', '', $tr_field)))))) . ': ' . fn___echo_tr_column($obj_type, $trs[0][$tr_field], $tr_field, true) . '</div>';
                            }
                        }

                        //Append ID:
                        $html_message .= '<div>Transaction: <a href="https://mench.com/ledger/fn___tr_json/' . $trs[0]['tr_id'] . '">#' . $trs[0]['tr_id'] . '</a></div>';

                        //Send Email:
                        $this->Chat_model->fn___dispatch_email($subscription['admin_emails'], $subscription['admin_en_ids'], $subject, $html_message);


                    }
                }
            }
        }


        //Return:
        return $insert_columns;
    }


    function fn___en_fetch($match_columns, $join_objects = array(), $limit = 0, $limit_offset = 0, $order_columns = array('en_trust_score' => 'DESC'), $select = '*', $group_by = null)
    {

        //Fetch the target entities:
        $this->db->select($select);
        $this->db->from('table_entities');
        foreach ($match_columns as $key => $value) {
            if (!is_null($value)) {
                $this->db->where($key, $value);
            } else {
                $this->db->where($key);
            }
        }
        if ($group_by) {
            $this->db->group_by($group_by);
        }
        foreach ($order_columns as $key => $value) {
            $this->db->order_by($key, $value);
        }
        if ($limit > 0) {
            $this->db->limit($limit, $limit_offset);
        }

        $q = $this->db->get();
        $res = $q->result_array();


        //Now fetch parents:
        foreach ($res as $key => $val) {

            //This will Count ALL the children:
            if (in_array('en__child_count', $join_objects)) {

                //ACount children:
                $res[$key]['en__child_count'] = $this->Matrix_model->fn___en_child_count($val['en_id']);

            }

            //This will fetch Children up to a maximum of $this->config->item('en_per_page')
            if (in_array('en__children', $join_objects)) {

                $res[$key]['en__children'] = $this->Database_model->fn___tr_fetch(array(
                    'tr_en_parent_id' => $val['en_id'],
                    'tr_type_en_id IN (' . join(',', $this->config->item('en_ids_4592')) . ')' => null, //Entity Link Connectors
                    'tr_status >=' => 0, //New+
                    'en_status >=' => 0, //New+
                ), array('en_child'), $this->config->item('en_per_page'), 0, array('en_trust_score' => 'DESC'));

                //TODO maybe consider en__grandchildren someday and add to UI?

            }


            if (in_array('en__actionplans', $join_objects)) {

                //Search & Append this Student's Action Plans:
                $res[$key]['en__actionplans'] = $this->Database_model->fn___tr_fetch(array(
                    'tr_en_parent_id' => $val['en_id'],
                    'tr_type_en_id' => 4235, //Action Plan
                    'tr_status >=' => 0, //New+
                ), array('in_child'), 0, 0, array('tr_order' => 'ASC'));

            }


            //Always fetch entity parents unless explicitly requested not to:
            if (in_array('skip_en__parents', $join_objects)) {

                $res[$key]['en__parents'] = array();

            } else {

                //Fetch parents by default:
                $res[$key]['en__parents'] = $this->Database_model->fn___tr_fetch(array(
                    'tr_type_en_id IN (' . join(',', $this->config->item('en_ids_4592')) . ')' => null, //Entity Link Connectors
                    'tr_en_child_id' => $val['en_id'], //This child entity
                    'tr_status >=' => 0, //New+
                    'en_status >=' => 0, //New+
                ), array('en_parent'), 0, 0, array('en_trust_score' => 'DESC'));

            }
        }

        return $res;
    }

    function fn___in_fetch($match_columns, $join_objects = array(), $limit = 0, $limit_offset = 0, $order_columns = array(), $select = '*', $group_by = null)
    {

        //The basic fetcher for intents
        $this->db->select($select);
        $this->db->from('table_intents');

        foreach ($match_columns as $key => $value) {
            $this->db->where($key, $value);
        }

        if ($group_by) {
            $this->db->group_by($group_by);
        }
        if (count($order_columns) > 0) {
            foreach ($order_columns as $key => $value) {
                $this->db->order_by($key, $value);
            }
        }
        if ($limit > 0) {
            $this->db->limit($limit, $limit_offset);
        }
        $q = $this->db->get();
        $ins = $q->result_array();

        foreach ($ins as $key => $value) {

            //Should we append intent messages?
            if (in_array('in__messages', $join_objects)) {
                $ins[$key]['in__messages'] = $this->Database_model->fn___tr_fetch(array(
                    'tr_status >=' => 0, //New+
                    'tr_type_en_id IN (' . join(',', $this->config->item('en_ids_4485')) . ')' => null, //All Intent messages
                    'tr_in_child_id' => $value['in_id'],
                ), array(), 0, 0, array('tr_order' => 'ASC'));
            }

            //Should we fetch all parent intentions?
            if (in_array('in__parents', $join_objects)) {

                $ins[$key]['in__parents'] = $this->Database_model->fn___tr_fetch(array(
                    'tr_status >=' => 0, //New+
                    'in_status >=' => 0, //New+
                    'tr_type_en_id IN (' . join(',', $this->config->item('en_ids_4486')) . ')' => null, //Intent Link Types
                    'tr_in_child_id' => $value['in_id'],
                ), array('in_parent')); //Note that parents do not need any sorting, since we only sort child intents

            }

            //Have we been asked to append any children/granchildren to this query?
            if (in_array('in__children', $join_objects) || in_array('in__grandchildren', $join_objects)) {

                //Fetch immediate children:
                $ins[$key]['in__children'] = $this->Database_model->fn___tr_fetch(array(
                    'tr_status >=' => 0, //New+
                    'in_status >=' => 0, //New+
                    'tr_type_en_id IN (' . join(',', $this->config->item('en_ids_4486')) . ')' => null, //Intent Link Types
                    'tr_in_parent_id' => $value['in_id'],
                ), array('in_child'), 0, 0, array('tr_order' => 'ASC')); //Child intents must be ordered


                if (in_array('in__grandchildren', $join_objects) && count($ins[$key]['in__children']) > 0) {
                    //Fetch second-level grandchildren intents:
                    foreach ($ins[$key]['in__children'] as $key2 => $value2) {

                        $ins[$key]['in__children'][$key2]['in__grandchildren'] = $this->Database_model->fn___tr_fetch(array(
                            'tr_status >=' => 0, //New+
                            'in_status >=' => 0, //New+
                            'tr_type_en_id IN (' . join(',', $this->config->item('en_ids_4486')) . ')' => null, //Intent Link Types
                            'tr_in_parent_id' => $value2['in_id'],
                        ), array('in_child'), 0, 0, array('tr_order' => 'ASC')); //Child intents must be ordered

                    }
                }
            }
        }

        //Return everything that was collected:
        return $ins;
    }

    function fn___tr_fetch($match_columns, $join_objects = array(), $limit = 100, $limit_offset = 0, $order_columns = array('tr_id' => 'DESC'), $select = '*', $group_by = null)
    {

        $this->db->select($select);
        $this->db->from('table_ledger');

        //Any intent joins?
        if (in_array('in_parent', $join_objects)) {
            $this->db->join('table_intents', 'tr_in_parent_id=in_id','left');
        } elseif (in_array('in_child', $join_objects)) {
            $this->db->join('table_intents', 'tr_in_child_id=in_id','left');
        }

        //Any entity joins?
        if (in_array('en_parent', $join_objects)) {
            $this->db->join('table_entities', 'tr_en_parent_id=en_id','left');
        } elseif (in_array('en_child', $join_objects)) {
            $this->db->join('table_entities', 'tr_en_child_id=en_id','left');
        } elseif (in_array('en_type', $join_objects)) {
            $this->db->join('table_entities', 'tr_type_en_id=en_id','left');
        } elseif (in_array('en_miner', $join_objects)) {
            $this->db->join('table_entities', 'tr_miner_en_id=en_id','left');
        }

        foreach ($match_columns as $key => $value) {
            if (!is_null($value)) {
                $this->db->where($key, $value);
            } else {
                $this->db->where($key);
            }
        }

        if ($group_by) {
            $this->db->group_by($group_by);
        }

        foreach ($order_columns as $key => $value) {
            $this->db->order_by($key, $value);
        }

        if ($limit > 0) {
            $this->db->limit($limit, $limit_offset);
        }
        $q = $this->db->get();
        return $q->result_array();
    }


    function fn___en_update($id, $update_columns, $external_sync = false, $tr_miner_en_id = 0)
    {

        /*
         *
         * $external_sync helps log a transaction for the new entity that is about to
         * be created but we yet dont have its entity ID to use in $tr_miner_en_id!
         *
         * */

        if (count($update_columns) == 0) {
            return false;
        }

        //We need to fetch existing data in two scenarios:
        if ($external_sync) {
            //Fetch current entity filed values so we can compare later on after we've updated it:
            $before_data = $this->Database_model->fn___en_fetch(array('en_id' => $id));

            //Make sure this was a valid id:
            if (!(count($before_data) == 1)) {
                return false;
            }
        }


        //Cleanup metadata if needed:
        if(isset($update_columns['en_metadata'])){
            $update_columns['en_metadata'] = serialize($update_columns['en_metadata']);
        }


        //Update:
        $this->db->where('en_id', $id);
        $this->db->update('table_entities', $update_columns);
        $affected_rows = $this->db->affected_rows();

        //Do we need to do any additional work?
        if ($affected_rows && $external_sync) {

            //Log modification transaction for every field changed:
            foreach ($update_columns as $key => $value) {

                //Has this value changed compared to what we initially had in DB?
                if (!($before_data[0][$key] == $value) && !in_array($key, array('en_metadata', 'en_trust_score'))) {

                    //Value has changed, log transaction:
                    $this->Database_model->fn___tr_create(array(
                        'tr_miner_en_id' => ($tr_miner_en_id > 0 ? $tr_miner_en_id : $id),
                        'tr_type_en_id' => 4263, //Entity Attribute Modified
                        'tr_en_child_id' => $id,
                        'tr_content' => 'Entity ' . ucwords(str_replace('_', ' ', str_replace('en_', '', $key))) . ' changed from [' . $before_data[0][$key] . '] to [' . $value . ']',
                        'tr_metadata' => array(
                            'en_id' => $id,
                            'field' => $key,
                            'before' => $before_data[0][$key],
                            'after' => $value,
                        ),
                    ));

                }

            }

            //Sync algolia:
            $algolia_sync = $this->Database_model->fn___update_algolia('en', $id);

        }

        return $affected_rows;
    }

    function fn___in_update($id, $update_columns, $external_sync = false, $tr_miner_en_id = 0)
    {

        if (count($update_columns) == 0) {
            return false;
        }

        if ($tr_miner_en_id > 0) {
            //Fetch current intent filed values so we can compare later on after we've updated it:
            $before_data = $this->Database_model->fn___in_fetch(array('in_id' => $id));

            //Make sure this was a valid id:
            if (!(count($before_data) == 1)) {
                return false;
            }
        }

        //Cleanup metadata if needed:
        if(isset($update_columns['in_metadata'])){
            $update_columns['in_metadata'] = serialize($update_columns['in_metadata']);
        }

        //Update:
        $this->db->where('in_id', $id);
        $this->db->update('table_intents', $update_columns);
        $affected_rows = $this->db->affected_rows();

        //Do we need to do any additional work?
        if ($affected_rows && $external_sync) {

            //Note that unlike entity modification, we require a miner entity ID to log the modification transaction:
            //Log modification transaction for every field changed:
            foreach ($update_columns as $key => $value) {

                //Has this value changed compared to what we initially had in DB?
                if (!($before_data[0][$key] == $value) && !in_array($key, array('in_metadata'))) {

                    //Value has changed, log transaction:
                    $this->Database_model->fn___tr_create(array(
                        'tr_miner_en_id' => $tr_miner_en_id,
                        'tr_type_en_id' => 4264, //Intent Attribute Modified
                        'tr_in_child_id' => $id,
                        'tr_content' => 'Intent ' . ucwords(str_replace('_', ' ', str_replace('in_', '', $key))) . ' changed from [' . $before_data[0][$key] . '] to [' . $value . ']',
                        'tr_metadata' => array(
                            'in_id' => $id,
                            'field' => $key,
                            'before' => $before_data[0][$key],
                            'after' => $value,
                        ),
                    ));

                }

            }

            //Sync algolia:
            $this->Database_model->fn___update_algolia('in', $id);

        }

        return $affected_rows;
    }

    function fn___tr_update($id, $update_columns, $tr_miner_en_id = 0)
    {

        if (count($update_columns) == 0) {
            return false;
        }

        if ($tr_miner_en_id > 0) {
            //Fetch transaction before updating:
            $before_data = $this->Database_model->fn___tr_fetch(array(
                'tr_id' => $id,
            ));

            //Make sure this was a valid id:
            if (!(count($before_data) == 1)) {
                return false;
            }
        }

        //Update metadata if needed:
        if(isset($update_columns['tr_metadata']) && is_array($update_columns['tr_metadata'])){
            $update_columns['tr_metadata'] = serialize($update_columns['tr_metadata']);
        }

        //Update:
        $this->db->where('tr_id', $id);
        $this->db->update('table_ledger', $update_columns);
        $affected_rows = $this->db->affected_rows();

        //Log changes if successful:
        if ($affected_rows && $tr_miner_en_id) {

            //Log modification transaction for every field changed:
            foreach ($update_columns as $key => $value) {

                //Has this value changed compared to what we initially had in DB?
                if ( !($before_data[0][$key] == $value) && in_array($key, array('tr_status', 'tr_content', 'tr_order', 'tr_en_parent_id', 'tr_en_child_id', 'tr_in_parent_id', 'tr_in_child_id', 'tr_metadata', 'tr_type_en_id'))) {

                    //Value has changed, log transaction:
                    $this->Database_model->fn___tr_create(array(
                        'tr_tr_id' => $id, //Transaction Reference
                        'tr_miner_en_id' => $tr_miner_en_id,
                        'tr_type_en_id' => 4242, //Transaction Attribute Modified
                        'tr_content' => 'Transaction ' . ucwords(str_replace('_', ' ', str_replace('tr_', '', $key))) . ' changed from [' . $before_data[0][$key] . '] to [' . $value . ']',
                        'tr_metadata' => array(
                            'tr_id' => $id,
                            'field' => $key,
                            'before' => $before_data[0][$key],
                            'after' => $value,
                        ),
                        //Copy old values for parent/child intent/entity links:
                        'tr_en_parent_id' => $before_data[0]['tr_en_parent_id'],
                        'tr_en_child_id'  => $before_data[0]['tr_en_child_id'],
                        'tr_in_parent_id' => $before_data[0]['tr_in_parent_id'],
                        'tr_in_child_id'  => $before_data[0]['tr_in_child_id'],
                    ));

                }
            }
        }

        return $affected_rows;
    }


    function fn___tr_max_order($match_columns)
    {

        //Counts the current highest order value
        $this->db->select('MAX(tr_order) as largest_order');
        $this->db->from('table_ledger');
        foreach ($match_columns as $key => $value) {
            $this->db->where($key, $value);
        }
        $q = $this->db->get();
        $stats = $q->row_array();
        if (count($stats) > 0) {
            return intval($stats['largest_order']);
        } else {
            //Nothing found:
            return 0;
        }
    }


    function fn___update_algolia($input_obj_type = null, $input_obj_id = 0)
    {

        /*
         *
         * Syncs intents/entities with Algolia Index
         *
         * */

        $valid_objects = array('en','in');

        if (!$this->config->item('enable_algolia')) {
            //Algolia is disabled, so avoid syncing:
            return array(
                'status' => 0,
                'message' => 'Algolia disabled',
            );
        } elseif($input_obj_type && !in_array($input_obj_type , $valid_objects)){
            return array(
                'status' => 0,
                'message' => 'Object type is invalid',
            );
        } elseif(($input_obj_type && !$input_obj_id) || ($input_obj_id && !$input_obj_type)){
            return array(
                'status' => 0,
                'message' => 'Must define both object type and ID',
            );
        }

        //Define the support objects indexed on algolia:
        $object_statuses = $this->config->item('object_statuses'); //Needed for intent Icon
        $input_obj_id = intval($input_obj_id);
        $limits = array();


        if (fn___is_dev()) {
            //Do a call on live as this does not work on local due to security limitations:
            return json_decode(fn___curl_html($this->config->item('algolia_remote') . "/cron/fn___update_algolia/" . ( $input_obj_type ? $input_obj_type . '/' . $input_obj_id : '' )));
        }

        //Load Algolia Index
        $search_index = fn___load_php_algolia('alg_index');


        //Which objects are we fetching?
        if ($input_obj_type) {

            //We'll only fetch a specific type:
            $fetch_objects = array($input_obj_type);

        } else {

            //Do both intents and entities:
            $fetch_objects = $valid_objects;

            //We need to update the entire index, so let's truncate it first:
            $search_index->clearIndex();

            //Boost processing power:
            fn___boost_power();

        }


        $synced_count = 0;
        foreach($fetch_objects as $loop_obj){

            //Fetch item(s) for updates including their parents:
            if ($loop_obj == 'in') {

                if($input_obj_id){
                    $limits['in_id'] = $input_obj_id;
                } else {
                    $limits['in_status >='] = 0; //New+
                }

                $db_rows = $this->Database_model->fn___in_fetch($limits, array('in__parents', 'in__messages'));

            } elseif ($loop_obj == 'en') {

                if($input_obj_id){
                    $limits['en_id'] = $input_obj_id;
                } else {
                    $limits['en_status >='] = 0; //New+
                }

                $db_rows = $this->Database_model->fn___en_fetch($limits, array('en__parents'));

            }


            //Did we find anything?
            if (count($db_rows) == 0) {
                continue;
            }



            //Build the index:
            $alg_array = array();
            foreach ($db_rows as $db_row) {

                //Prepare variables:
                unset($export_row);
                $export_row = array();


                //Attempt to fetch Algolia object ID from object Metadata:
                if($input_obj_type){

                    if (strlen($db_row[$input_obj_type . '_metadata']) > 0) {

                        //We have a metadata, so we might have the Algolia ID stored. Let's check:
                        $metadata = unserialize($db_row[$input_obj_type . '_metadata']);
                        if (isset($metadata[$input_obj_type . '_algolia_id']) && intval($metadata[$input_obj_type . '_algolia_id']) > 0) {
                            //We found it! Let's just update existing algolia record
                            $export_row['objectID'] = intval($metadata[$input_obj_type . '_algolia_id']);
                        }

                    }

                } else {

                    //Clear possible metadata algolia ID's that have been cached:
                    $this->Matrix_model->fn___metadata_update($loop_obj, $db_row, array(
                        $loop_obj . '_algolia_id' => null, //Since all objects have been mass removed!
                    ));

                }



                //Now build object-specific index:
                if ($loop_obj == 'en') {

                    $export_row['alg_obj_is_in'] = 0;
                    $export_row['alg_obj_id'] = intval($db_row['en_id']);
                    $export_row['alg_obj_status'] = intval($db_row['en_status']);
                    $export_row['alg_obj_icon'] = ( strlen($db_row['en_icon']) > 0 ? $db_row['en_icon'] : '<i class="fas fa-at grey-at"></i>' );
                    $export_row['alg_obj_name'] = $db_row['en_name'];
                    $export_row['alg_obj_postfix'] = ''; //Entities have no post-fix at this time

                    //Add keywords:
                    $export_row['alg_obj_keywords'] = '';
                    foreach ($db_row['en__parents'] as $tr) {
                        if (strlen($tr['tr_content']) > 0) {
                            $export_row['alg_obj_keywords'] .= $tr['tr_content'] . ' ';
                        }
                    }
                    $export_row['alg_obj_keywords'] = trim(strip_tags($export_row['alg_obj_keywords']));

                } elseif ($loop_obj == 'in') {

                    $export_row['alg_obj_is_in'] = 1;
                    $export_row['alg_obj_id'] = intval($db_row['in_id']);
                    $export_row['alg_obj_status'] = intval($db_row['in_status']);
                    $export_row['alg_obj_icon'] = $object_statuses['in_is_any'][$db_row['in_is_any']]['s_icon']; //Entity type icon
                    $export_row['alg_obj_name'] = $db_row['in_outcome'];
                    $export_row['alg_obj_postfix'] = '<span class="alg-postfix"><i class="fal fa-clock"></i>' . fn___echo_time_range($db_row) . '</span>';

                    //Add keywords:
                    $export_row['alg_obj_keywords'] = '';
                    foreach ($db_row['in__messages'] as $tr) {
                        $export_row['alg_obj_keywords'] .= $tr['tr_content'] . ' ';
                    }
                    $export_row['alg_obj_keywords'] = trim(strip_tags($export_row['alg_obj_keywords']));

                }

                //Add to main array
                array_push($alg_array, $export_row);

            }



            //Now let's see what to do with the index (Update, Create or delete)
            if ($input_obj_type) {

                //We should have fetched a single item only, meaning $alg_array[0] is what we are focused on...

                //What's the status? Is it active or should it be removed?
                if ($db_rows[0][$input_obj_type . '_status'] >= 0) {

                    if (isset($alg_array[0]['objectID'])) {

                        //Update existing index:
                        $algolia_results = $search_index->saveObjects($alg_array);

                    } else {

                        //We do not have an index to an Algolia object locally, so create a new index:
                        $algolia_results = $search_index->addObjects($alg_array);

                        //Now update local database with the new objectIDs:
                        if (isset($algolia_results['objectIDs']) && count($algolia_results['objectIDs']) > 0) {
                            foreach ($algolia_results['objectIDs'] as $key => $algolia_id) {
                                $this->Matrix_model->fn___metadata_update($input_obj_type, $db_rows[0], array(
                                    $input_obj_type . '_algolia_id' => $algolia_id, //The newly created algolia object
                                ));
                            }
                        }

                    }

                    $synced_count += count($algolia_results['objectIDs']);

                } else {

                    if (isset($alg_array[0]['objectID'])) {

                        //Object is removed locally but still indexed remotely on Algolia, so let's remove it from Algolia:

                        //Remove from algolia:
                        $algolia_results = $search_index->deleteObject($alg_array[0]['objectID']);

                        //also set its algolia_id to 0 locally:
                        $this->Matrix_model->fn___metadata_update($input_obj_type, $db_rows[0], array(
                            $input_obj_type . '_algolia_id' => null, //Since this item has been removed!
                        ));

                        $synced_count += count($algolia_results['objectIDs']);

                    } else {
                        //Nothing to do here since we don't have the Algolia object locally!
                    }

                }

            } else {

                /*
                 *
                 * This is a mass update request.
                 *
                 * All remote objects have already been removed from the Algolia
                 * index & metadata algolia_ids have all been set to zero!
                 *
                 * We're ready to create new items and update local
                 *
                 * */

                $algolia_results = $search_index->addObjects($alg_array);

                //Now update database with the objectIDs:
                if (isset($algolia_results['objectIDs']) && count($algolia_results['objectIDs']) > 0) {

                    foreach ($algolia_results['objectIDs'] as $key => $algolia_id) {
                        $this->Matrix_model->fn___metadata_update($input_obj_type, $db_rows[$key], array(
                            $input_obj_type . '_algolia_id' => $algolia_id,
                        ));
                    }

                }

                $synced_count += count($algolia_results['objectIDs']);

            }

        }



        //Return results:
        return array(
            'status' => ( $synced_count > 0 ? 1 : 0),
            'message' => $synced_count . ' objects sync with Algolia',
        );

    }

}
