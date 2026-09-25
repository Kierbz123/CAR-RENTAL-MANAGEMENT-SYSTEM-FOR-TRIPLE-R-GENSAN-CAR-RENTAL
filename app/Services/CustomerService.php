<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDO;
use RuntimeException;
use TripleR\Repositories\CustomerRepository;

final class CustomerService
{
    private const TYPES=['walk_in','online','corporate','repeat','referral'];

    public function __construct(private readonly PDO $db,private readonly CustomerRepository $customers,private readonly CustomerPiiCipher $cipher) {}

    public function validateCustomer(array $input): array
    {
        $type=(string)($input['customer_type']??'');
        if (!in_array($type,self::TYPES,true)) throw new RuntimeException('Choose a valid customer type.');
        $name=trim((string)($input['full_name']??'')); $company=trim((string)($input['company_name']??'')); $referral=trim((string)($input['referral_source']??''));
        if ($name==='' || mb_strlen($name)>160) throw new RuntimeException('Enter a customer name up to 160 characters.');
        if ($type==='corporate' && ($company==='' || mb_strlen($company)>160)) throw new RuntimeException('Company name is required for corporate customers.');
        if ($type!=='corporate') $company='';
        if ($type==='referral' && ($referral==='' || mb_strlen($referral)>160)) throw new RuntimeException('Referral source is required for referral customers.');
        if ($type!=='referral') $referral='';
        return ['customer_type'=>$type,'full_name'=>$name,'company_name'=>$company===''?null:$company,'referral_source'=>$referral===''?null:$referral];
    }

    public function create(array $input,int $actor): int
    {
        $customer=$this->validateCustomer($input);
        $contacts=[];
        foreach (['phone','email'] as $type) { $value=trim((string)($input[$type]??'')); if ($value!=='') $contacts[]=[$type,$this->contactMaterial($type,$value)]; }
        $document=$this->documentMaterial($input);
        return $this->transaction(function() use ($customer,$contacts,$document): int {
            $id=$this->customers->create($customer);
            foreach ($contacts as [$type,$material]) $this->customers->addContact($id,$type,$material['cipher'],$material['fingerprint'],true);
            if ($document!==null) $this->customers->insertDocument($id,$document['type'],$document['cipher'],$document['fingerprint'],$document['expiry']);
            return $id;
        },true,$actor);
    }

    public function update(int $id,array $input): void
    {
        $data=$this->validateCustomer($input);
        $this->transaction(function() use ($id,$data): void {
            if (!$this->customers->find($id,true)) throw new RuntimeException('Customer not found.');
            $this->customers->update($id,$data);
        });
    }

    public function addContact(int $customerId,string $type,string $value,bool $primary): int
    {
        $material=$this->contactMaterial($type,$value);
        return $this->transaction(function() use ($customerId,$type,$material,$primary): int {
            if (!$this->customers->find($customerId,true)) throw new RuntimeException('Customer not found.');
            if ($this->customers->contactExists($customerId,$type,$material['fingerprint'])) throw new RuntimeException('That contact is already recorded on this customer.');
            return $this->customers->addContact($customerId,$type,$material['cipher'],$material['fingerprint'],$primary);
        });
    }

    public function updateContact(int $customerId,int $contactId,string $type,string $value,bool $primary): void
    {
        $material=$this->contactMaterial($type,$value);
        $this->transaction(function() use ($customerId,$contactId,$type,$material,$primary): void {
            if (!$this->customers->find($customerId,true)) throw new RuntimeException('Customer not found.');
            if (!$this->customers->findContact($contactId,$customerId)) throw new RuntimeException('Contact not found.');
            if ($this->customers->contactExists($customerId,$type,$material['fingerprint'],$contactId)) throw new RuntimeException('That contact is already recorded on this customer.');
            $this->customers->updateContact($contactId,$customerId,$type,$material['cipher'],$material['fingerprint'],$primary);
        });
    }

    public function removeContact(int $customerId,int $contactId): void
    {
        $this->transaction(function() use ($customerId,$contactId): void {
            if (!$this->customers->find($customerId,true)) throw new RuntimeException('Customer not found.');
            if (!$this->customers->findContact($contactId,$customerId)) throw new RuntimeException('Contact not found.');
            $this->customers->removeContact($contactId,$customerId);
        });
    }

    public function addDocument(int $customerId,array $input,int $actor): int
    {
        $doc=$this->documentMaterial($input); if ($doc===null) throw new RuntimeException('Enter an identity document number.');
        return $this->transaction(function() use ($customerId,$doc): int {
            if (!$this->customers->find($customerId,true)) throw new RuntimeException('Customer not found.');
            return $this->customers->insertDocument($customerId,$doc['type'],$doc['cipher'],$doc['fingerprint'],$doc['expiry']);
        },true,$actor);
    }

    public function updateDocument(int $customerId,int $documentId,array $input,int $actor): void
    {
        $doc=$this->documentMaterial($input); if ($doc===null) throw new RuntimeException('Enter an identity document number.');
        $this->transaction(function() use ($customerId,$documentId,$doc): void {
            if (!$this->customers->find($customerId,true)) throw new RuntimeException('Customer not found.');
            if (!$this->customers->findDocument($documentId,$customerId,true)) throw new RuntimeException('Identity document not found.');
            $this->customers->updateDocument($documentId,$doc['type'],$doc['cipher'],$doc['fingerprint'],$doc['expiry']);
        },true,$actor);
    }

    public function addNote(int $customerId,string $text,int $actor): void
    {
        $text=trim($text); if ($text==='' || mb_strlen($text)>10000) throw new RuntimeException('Enter a note up to 10,000 characters.');
        $this->transaction(function() use ($customerId,$text,$actor): void {
            if (!$this->customers->find($customerId,true)) throw new RuntimeException('Customer not found.');
            $this->customers->appendNote($customerId,'general',$text,$actor);
        });
    }

    public function blacklist(int $id,string $reason,int $actor): void
    {
        $reason=$this->reason($reason);
        $this->transaction(function() use ($id,$reason,$actor): void {
            $customer=$this->customers->find($id,true);
            if (!$customer) throw new RuntimeException('Customer not found.');
            if ((int)$customer['is_blacklisted']===1) throw new RuntimeException('Customer is already blacklisted.');
            $stmt=$this->db->prepare('UPDATE customers SET is_blacklisted=1,blacklist_reason=:reason,blacklisted_at=UTC_TIMESTAMP(6),blacklisted_by_user_id=:actor WHERE customer_id=:id AND deleted_at IS NULL');
            $stmt->execute(['reason'=>$reason,'actor'=>$actor,'id'=>$id]);
            $this->customers->appendNote($id,'blacklist',$reason,$actor);
        });
    }

    public function unblacklist(int $id,string $reason,int $actor): void
    {
        $reason=$this->reason($reason);
        $this->transaction(function() use ($id,$reason,$actor): void {
            $customer=$this->customers->find($id,true);
            if (!$customer) throw new RuntimeException('Customer not found.');
            if ((int)$customer['is_blacklisted']!==1) throw new RuntimeException('Customer is not blacklisted.');
            $stmt=$this->db->prepare('UPDATE customers SET is_blacklisted=0,blacklist_reason=NULL,blacklisted_at=NULL,blacklisted_by_user_id=NULL WHERE customer_id=:id AND deleted_at IS NULL'); $stmt->execute(['id'=>$id]);
            $this->customers->appendNote($id,'unblacklist',$reason,$actor);
        });
    }

    public function softDelete(int $id): void
    {
        $this->transaction(function() use ($id): void {
            if (!$this->customers->find($id,true)) throw new RuntimeException('Customer not found.');
            if ($this->hasRentalAgreements()) {
                $stmt=$this->db->prepare("SELECT agreement_id FROM rental_agreements WHERE customer_id=:id AND status IN ('reserved','confirmed','active','returned') LIMIT 1"); $stmt->execute(['id'=>$id]);
                if ($stmt->fetchColumn()!==false) throw new RuntimeException('Customer has an open rental agreement and cannot be removed.');
            }
            $this->db->prepare('UPDATE customers SET deleted_at=UTC_TIMESTAMP(6) WHERE customer_id=:id AND deleted_at IS NULL')->execute(['id'=>$id]);
        });
    }

    public function revealContact(int $customerId,int $contactId): string
    {
        $contact=$this->customers->findContact($contactId,$customerId); if (!$contact) throw new RuntimeException('Contact not found.');
        return $this->cipher->decrypt($contact['contact_ciphertext'],'customer-contact:'.$contact['contact_type']);
    }

    public function revealDocument(int $customerId,int $documentId): string
    {
        $doc=$this->customers->findDocument($documentId,$customerId); if (!$doc) throw new RuntimeException('Identity document not found.');
        $normalized=$this->cipher->decrypt($doc['document_ciphertext'],'customer-document:'.$doc['document_type']);
        return $normalized;
    }

    public function maskContact(array $contact): string
    {
        $value=$this->cipher->decrypt($contact['contact_ciphertext'],'customer-contact:'.$contact['contact_type']);
        return CustomerPiiCipher::mask($contact['contact_type'],$value);
    }

    public function maskDocument(array $document): string
    {
        $value=$this->cipher->decrypt($document['document_ciphertext'],'customer-document:'.$document['document_type']);
        $normalized=CustomerPiiCipher::normalizeDocumentNumber($value);
        return '••••'.substr($normalized,-4);
    }

    private function contactMaterial(string $type,string $value): array
    {
        $normalized=CustomerPiiCipher::normalizeContact($type,$value);
        return ['cipher'=>$this->cipher->encrypt($normalized,'customer-contact:'.$type),'fingerprint'=>$this->cipher->fingerprint('contact:'.$type,$normalized)];
    }

    private function documentMaterial(array $input): ?array
    {
        $raw=trim((string)($input['document_number']??'')); $rawType=trim((string)($input['document_type']??'')); $rawExpiry=trim((string)($input['expires_on']??''));
        if ($raw==='' && $rawType==='' && $rawExpiry==='') return null;
        if ($raw==='') throw new RuntimeException('Enter an identity document number.');
        $type=CustomerPiiCipher::normalizeDocumentType($rawType); $normalized=CustomerPiiCipher::normalizeDocumentNumber($raw);
        $expiry=$this->date($rawExpiry);
        return ['type'=>$type,'cipher'=>$this->cipher->encrypt($raw,'customer-document:'.$type),'fingerprint'=>$this->cipher->fingerprint('identity-document:'.$type,$normalized),'expiry'=>$expiry];
    }

    private function hasRentalAgreements(): bool
    {
        $stmt=$this->db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='rental_agreements' LIMIT 1"); $stmt->execute(); return $stmt->fetchColumn()!==false;
    }

    private function transaction(callable $work,bool $identityWrite=false,?int $actor=null): mixed
    {
        $this->db->beginTransaction();
        try {
            if ($identityWrite) {
                if ($actor===null) throw new RuntimeException('An authenticated actor is required for identity document changes.');
                $stmt=$this->db->prepare('SET @triple_r_actor_user_id = :actor'); $stmt->execute(['actor'=>$actor]);
            }
            $result=$work(); $this->db->commit(); return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack(); throw $error;
        } finally {
            if ($identityWrite) $this->db->exec('SET @triple_r_actor_user_id = NULL');
        }
    }

    private function reason(string $reason): string
    {
        $reason=trim($reason); if ($reason==='' || mb_strlen($reason)>500) throw new RuntimeException('Enter a reason up to 500 characters.'); return $reason;
    }

    private function date(string $value): ?string
    {
        if ($value==='') return null; $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$date || $date->format('Y-m-d')!==$value) throw new RuntimeException('Enter a valid document expiry date.'); return $value;
    }
}
