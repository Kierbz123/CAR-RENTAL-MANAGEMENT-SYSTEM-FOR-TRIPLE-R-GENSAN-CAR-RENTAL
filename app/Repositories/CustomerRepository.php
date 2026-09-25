<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class CustomerRepository
{
    public function __construct(private readonly PDO $db) {}

    public function list(?string $type,?string $search): array
    {
        $sql='SELECT * FROM customers WHERE deleted_at IS NULL'; $params=[];
        if ($type!==null && $type!=='') { $sql.=' AND customer_type=:type'; $params['type']=$type; }
        if ($search!==null && trim($search)!=='') { $sql.=' AND (full_name LIKE :search OR company_name LIKE :search)'; $params['search']='%'.trim($search).'%'; }
        $sql.=' ORDER BY full_name,customer_id'; $stmt=$this->db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
    }

    public function find(int $id,bool $lock=false,bool $includeDeleted=false): ?array
    {
        $sql='SELECT * FROM customers WHERE customer_id=:id'; if (!$includeDeleted) $sql.=' AND deleted_at IS NULL'; if ($lock) $sql.=' FOR UPDATE';
        $stmt=$this->db->prepare($sql); $stmt->execute(['id'=>$id]); $row=$stmt->fetch(); return $row?:null;
    }

    public function eligibleForBooking(): array
    {
        return $this->db->query('SELECT customer_id,customer_type,full_name,company_name FROM customers WHERE is_blacklisted=0 AND deleted_at IS NULL ORDER BY full_name,customer_id')->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt=$this->db->prepare('INSERT INTO customers (customer_type,full_name,company_name,referral_source) VALUES (:type,:name,:company,:referral)');
        $stmt->execute(['type'=>$data['customer_type'],'name'=>$data['full_name'],'company'=>$data['company_name'],'referral'=>$data['referral_source']]); return (int)$this->db->lastInsertId();
    }

    public function update(int $id,array $data): void
    {
        $stmt=$this->db->prepare('UPDATE customers SET customer_type=:type,full_name=:name,company_name=:company,referral_source=:referral WHERE customer_id=:id AND deleted_at IS NULL');
        $stmt->execute(['type'=>$data['customer_type'],'name'=>$data['full_name'],'company'=>$data['company_name'],'referral'=>$data['referral_source'],'id'=>$id]);
    }

    public function contacts(int $customerId): array
    {
        $stmt=$this->db->prepare('SELECT * FROM customer_contacts WHERE customer_id=:id AND deleted_at IS NULL ORDER BY is_primary DESC,contact_type,contact_id'); $stmt->execute(['id'=>$customerId]); return $stmt->fetchAll();
    }

    public function findContact(int $id,int $customerId): ?array
    {
        $stmt=$this->db->prepare('SELECT * FROM customer_contacts WHERE contact_id=:contact AND customer_id=:customer AND deleted_at IS NULL'); $stmt->execute(['contact'=>$id,'customer'=>$customerId]); $row=$stmt->fetch(); return $row?:null;
    }

    public function contactExists(int $customerId,string $type,string $fingerprint,?int $exceptId=null): bool
    {
        $sql='SELECT 1 FROM customer_contacts WHERE customer_id=:customer AND contact_type=:type AND contact_fingerprint=:fingerprint AND deleted_at IS NULL'; $params=['customer'=>$customerId,'type'=>$type,'fingerprint'=>$fingerprint];
        if ($exceptId!==null) { $sql.=' AND contact_id<>:except'; $params['except']=$exceptId; }
        $sql.=' LIMIT 1'; $stmt=$this->db->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn()!==false;
    }

    public function addContact(int $customerId,string $type,string $cipher,string $fingerprint,bool $primary): int
    {
        if ($primary) { $clear=$this->db->prepare('UPDATE customer_contacts SET is_primary=0 WHERE customer_id=:customer AND contact_type=:type AND deleted_at IS NULL'); $clear->execute(['customer'=>$customerId,'type'=>$type]); }
        $stmt=$this->db->prepare('INSERT INTO customer_contacts (customer_id,contact_type,contact_ciphertext,contact_fingerprint,is_primary) VALUES (:customer,:type,:cipher,:fingerprint,:primary)');
        $stmt->execute(['customer'=>$customerId,'type'=>$type,'cipher'=>$cipher,'fingerprint'=>$fingerprint,'primary'=>$primary?1:0]); return (int)$this->db->lastInsertId();
    }

    public function updateContact(int $id,int $customerId,string $type,string $cipher,string $fingerprint,bool $primary): void
    {
        if ($primary) { $clear=$this->db->prepare('UPDATE customer_contacts SET is_primary=0 WHERE customer_id=:customer AND contact_type=:type AND contact_id<>:contact AND deleted_at IS NULL'); $clear->execute(['customer'=>$customerId,'type'=>$type,'contact'=>$id]); }
        $stmt=$this->db->prepare('UPDATE customer_contacts SET contact_type=:type,contact_ciphertext=:cipher,contact_fingerprint=:fingerprint,is_primary=:primary WHERE contact_id=:contact AND customer_id=:customer AND deleted_at IS NULL');
        $stmt->execute(['type'=>$type,'cipher'=>$cipher,'fingerprint'=>$fingerprint,'primary'=>$primary?1:0,'contact'=>$id,'customer'=>$customerId]);
    }

    public function removeContact(int $id,int $customerId): void
    {
        $stmt=$this->db->prepare('UPDATE customer_contacts SET deleted_at=UTC_TIMESTAMP(6),is_primary=0 WHERE contact_id=:contact AND customer_id=:customer AND deleted_at IS NULL'); $stmt->execute(['contact'=>$id,'customer'=>$customerId]);
    }

    public function documents(int $customerId): array
    {
        $stmt=$this->db->prepare('SELECT * FROM customer_identity_documents WHERE customer_id=:id ORDER BY document_type,document_id'); $stmt->execute(['id'=>$customerId]); return $stmt->fetchAll();
    }

    public function findDocument(int $id,int $customerId,bool $lock=false): ?array
    {
        $stmt=$this->db->prepare('SELECT * FROM customer_identity_documents WHERE document_id=:document AND customer_id=:customer' . ($lock?' FOR UPDATE':'')); $stmt->execute(['document'=>$id,'customer'=>$customerId]); $row=$stmt->fetch(); return $row?:null;
    }

    public function insertDocument(int $customerId,string $type,string $cipher,string $fingerprint,?string $expiry): int
    {
        $stmt=$this->db->prepare('INSERT INTO customer_identity_documents (customer_id,document_type,document_ciphertext,document_fingerprint,expires_on) VALUES (:customer,:type,:cipher,:fingerprint,:expiry)');
        $stmt->execute(['customer'=>$customerId,'type'=>$type,'cipher'=>$cipher,'fingerprint'=>$fingerprint,'expiry'=>$expiry]); return (int)$this->db->lastInsertId();
    }

    public function updateDocument(int $documentId,string $type,string $cipher,string $fingerprint,?string $expiry): void
    {
        $stmt=$this->db->prepare('UPDATE customer_identity_documents SET document_type=:type,document_ciphertext=:cipher,document_fingerprint=:fingerprint,expires_on=:expiry WHERE document_id=:id');
        $stmt->execute(['type'=>$type,'cipher'=>$cipher,'fingerprint'=>$fingerprint,'expiry'=>$expiry,'id'=>$documentId]);
    }

    public function notes(int $customerId): array
    {
        $stmt=$this->db->prepare('SELECT n.*,u.email AS author_email FROM customer_notes n JOIN users u ON u.id=n.created_by_user_id WHERE n.customer_id=:id ORDER BY n.created_at,n.note_id'); $stmt->execute(['id'=>$customerId]); return $stmt->fetchAll();
    }

    public function documentAuditHistory(int $customerId): array
    {
        $stmt=$this->db->prepare('SELECT a.*,u.email AS actor_email FROM customer_identity_document_audit_logs a JOIN users u ON u.id=a.actor_user_id WHERE a.customer_id=:id ORDER BY a.created_at,a.audit_id'); $stmt->execute(['id'=>$customerId]); return $stmt->fetchAll();
    }

    public function rentalHistory(int $customerId): array
    {
        $table=$this->db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='rental_agreements' LIMIT 1"); $table->execute();
        if ($table->fetchColumn()===false) return [];
        $stmt=$this->db->prepare('SELECT agreement_id,status,start_date,end_date,created_at FROM rental_agreements WHERE customer_id=:id ORDER BY created_at DESC,agreement_id DESC'); $stmt->execute(['id'=>$customerId]); return $stmt->fetchAll();
    }

    public function appendNote(int $customerId,string $type,string $text,int $actor): void
    {
        $stmt=$this->db->prepare('INSERT INTO customer_notes (customer_id,note_type,note_text,created_by_user_id) VALUES (:customer,:type,:text,:actor)'); $stmt->execute(['customer'=>$customerId,'type'=>$type,'text'=>$text,'actor'=>$actor]);
    }
}
