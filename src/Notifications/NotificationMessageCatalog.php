<?php

declare(strict_types=1);

namespace ChambreRose;

final class NotificationMessageCatalog
{
    /** @var array<string, array{title: array<string, string>, body: array<string, string>}> */
    private const MESSAGES = [
        'MESSAGE_RECEIVED' => [
            'title' => ['en' => 'New private message', 'fr' => 'Nouveau message privé', 'pt' => 'Nova mensagem privada'],
            'body' => [
                'en' => 'You received a private message from {senderName}.',
                'fr' => 'Vous avez reçu un message privé de {senderName}.',
                'pt' => 'Você recebeu uma mensagem privada de {senderName}.',
            ],
        ],
        'PROFILE_REVIEWED' => [
            'title' => ['en' => 'New profile review', 'fr' => 'Nouvel avis sur le profil', 'pt' => 'Nova avaliação no perfil'],
            'body' => [
                'en' => 'A member left a new review on your profile.',
                'fr' => 'Un membre a laissé un nouvel avis sur votre profil.',
                'pt' => 'Um membro deixou uma nova avaliação no seu perfil.',
            ],
        ],
        'PROFILE_SELECTED' => [
            'title' => ['en' => 'New profile selection', 'fr' => 'Nouvelle sélection du profil', 'pt' => 'Nova seleção no perfil'],
            'body' => [
                'en' => '{memberName} selected an option from your profile.',
                'fr' => '{memberName} a sélectionné une option de votre profil.',
                'pt' => '{memberName} selecionou uma opção do seu perfil.',
            ],
        ],
        'PROFILE_FAVORITED' => [
            'title' => ['en' => 'New favorite', 'fr' => 'Nouveau favori', 'pt' => 'Novo favorito'],
            'body' => [
                'en' => 'A member added your profile to favorites.',
                'fr' => 'Un membre a ajouté votre profil à ses favoris.',
                'pt' => 'Um membro adicionou seu perfil aos favoritos.',
            ],
        ],
        'PRODUCT_PURCHASED' => [
            'title' => ['en' => 'New product purchase', 'fr' => 'Nouvel achat de produit', 'pt' => 'Nova compra de produto'],
            'body' => [
                'en' => 'A member purchased {productName}.',
                'fr' => 'Un membre a acheté {productName}.',
                'pt' => 'Um membro comprou {productName}.',
            ],
        ],
        'PROFILE_UPDATED' => [
            'title' => ['en' => 'Account details updated', 'fr' => 'Informations du compte mises à jour', 'pt' => 'Dados da conta atualizados'],
            'body' => [
                'en' => 'Your Chambre Rose account details were updated successfully.',
                'fr' => 'Les informations de votre compte Chambre Rose ont été mises à jour.',
                'pt' => 'Os dados da sua conta Chambre Rose foram atualizados.',
            ],
        ],
        'PASSWORD_RESET_REQUESTED' => [
            'title' => ['en' => 'Password reset requested', 'fr' => 'Réinitialisation du mot de passe demandée', 'pt' => 'Redefinição de senha solicitada'],
            'body' => [
                'en' => 'Password reset instructions were requested for your account. Contact support if this was not you.',
                'fr' => 'Des instructions de réinitialisation ont été demandées pour votre compte. Contactez le support si vous n’êtes pas à l’origine de cette demande.',
                'pt' => 'Foram solicitadas instruções para redefinir a senha da sua conta. Fale com o suporte caso não tenha sido você.',
            ],
        ],
        'PASSWORD_CHANGED' => [
            'title' => ['en' => 'Password changed', 'fr' => 'Mot de passe modifié', 'pt' => 'Senha alterada'],
            'body' => [
                'en' => 'Your Chambre Rose password was changed. Contact support if this was not you.',
                'fr' => 'Votre mot de passe Chambre Rose a été modifié. Contactez le support si vous n’êtes pas à l’origine de cette modification.',
                'pt' => 'Sua senha da Chambre Rose foi alterada. Fale com o suporte caso não tenha sido você.',
            ],
        ],
        'VIP_ACTIVATED' => [
            'title' => ['en' => 'VIP access activated', 'fr' => 'Accès VIP activé', 'pt' => 'Acesso VIP ativado'],
            'body' => [
                'en' => 'Your VIP access is now active. Private conversations are available.',
                'fr' => 'Votre accès VIP est maintenant actif. Les conversations privées sont disponibles.',
                'pt' => 'Seu acesso VIP está ativo. As conversas privadas estão disponíveis.',
            ],
        ],
        'VIP_DEACTIVATED' => [
            'title' => ['en' => 'VIP access changed', 'fr' => 'Accès VIP modifié', 'pt' => 'Acesso VIP alterado'],
            'body' => [
                'en' => 'Your VIP access is no longer active.',
                'fr' => 'Votre accès VIP n’est plus actif.',
                'pt' => 'Seu acesso VIP não está mais ativo.',
            ],
        ],
        'ROLE_CHANGED' => [
            'title' => ['en' => 'Account role updated', 'fr' => 'Rôle du compte mis à jour', 'pt' => 'Função da conta atualizada'],
            'body' => [
                'en' => 'Your account role is now {role}.',
                'fr' => 'Le rôle de votre compte est maintenant {role}.',
                'pt' => 'A função da sua conta agora é {role}.',
            ],
        ],
        'ACCOUNT_APPROVED' => [
            'title' => ['en' => 'Account approved', 'fr' => 'Compte approuvé', 'pt' => 'Conta aprovada'],
            'body' => [
                'en' => 'Your professional account was approved and is now visible.',
                'fr' => 'Votre compte professionnel a été approuvé et est maintenant visible.',
                'pt' => 'Sua conta profissional foi aprovada e agora está visível.',
            ],
        ],
        'ACCOUNT_REJECTED' => [
            'title' => ['en' => 'Account review completed', 'fr' => 'Examen du compte terminé', 'pt' => 'Análise da conta concluída'],
            'body' => [
                'en' => 'Your professional account was not approved. Open your account for details.',
                'fr' => 'Votre compte professionnel n’a pas été approuvé. Ouvrez votre compte pour consulter les détails.',
                'pt' => 'Sua conta profissional não foi aprovada. Abra sua conta para ver os detalhes.',
            ],
        ],
        'DAILY_DIGEST' => [
            'title' => ['en' => 'Your daily summary', 'fr' => 'Votre résumé quotidien', 'pt' => 'Seu resumo diário'],
            'body' => [
                'en' => 'New account and marketplace updates are waiting for you.',
                'fr' => 'De nouvelles mises à jour du compte et de la boutique vous attendent.',
                'pt' => 'Há novas atualizações da conta e do marketplace esperando por você.',
            ],
        ],
    ];

    public static function supports(string $eventType): bool
    {
        return isset(self::MESSAGES[$eventType]);
    }

    /**
     * @param array<string, scalar|null> $parameters
     * @return array{title: string, body: string}
     */
    public static function render(
        string $eventType,
        array $parameters = [],
        string $locale = 'en',
        string $fallbackTitle = 'Chambre Rose',
        string $fallbackBody = ''
    ): array {
        $message = self::MESSAGES[$eventType] ?? null;
        if ($message === null) {
            return ['title' => $fallbackTitle, 'body' => $fallbackBody];
        }

        $language = in_array(strtolower($locale), ['en', 'fr', 'pt'], true) ? strtolower($locale) : 'en';
        $values = self::values($parameters, $language);

        return [
            'title' => strtr($message['title'][$language], $values),
            'body' => strtr($message['body'][$language], $values),
        ];
    }

    /**
     * @param array<string, scalar|null> $parameters
     * @return array<string, string>
     */
    private static function values(array $parameters, string $locale): array
    {
        $defaults = [
            'senderName' => ['en' => 'a member', 'fr' => 'un membre', 'pt' => 'um membro'],
            'memberName' => ['en' => 'A member', 'fr' => 'Un membre', 'pt' => 'Um membro'],
            'productName' => ['en' => 'one of your products', 'fr' => 'l’un de vos produits', 'pt' => 'um dos seus produtos'],
            'role' => ['en' => 'member', 'fr' => 'membre', 'pt' => 'membro'],
        ];
        $values = [];
        foreach ($defaults as $name => $localizedDefaults) {
            $value = trim((string) ($parameters[$name] ?? ''));
            if ($name === 'role' && $value !== '') {
                $value = self::role($value, $locale);
            }
            $values['{' . $name . '}'] = $value === '' ? $localizedDefaults[$locale] : $value;
        }

        return $values;
    }

    private static function role(string $role, string $locale): string
    {
        $roles = [
            'ADMIN' => ['en' => 'administrator', 'fr' => 'administrateur', 'pt' => 'administrador'],
            'VISITOR' => ['en' => 'visitor', 'fr' => 'visiteur', 'pt' => 'visitante'],
            'ESCORT' => ['en' => 'companion', 'fr' => 'accompagnante', 'pt' => 'acompanhante'],
            'STORE' => ['en' => 'store', 'fr' => 'établissement', 'pt' => 'loja'],
        ];
        $normalized = strtoupper($role);

        return $roles[$normalized][$locale] ?? $role;
    }
}
