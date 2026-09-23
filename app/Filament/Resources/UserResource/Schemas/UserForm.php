<?php

namespace App\Filament\Resources\UserResource\Schemas;

use App\Forms\Components\TenantSelect;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class UserForm
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('使用者資訊')
                    ->description('設定使用者的基本帳號資訊')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('名稱')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('email')
                                    ->label('電子郵件')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('存取與角色')
                    ->description('設定使用者所屬租戶與系統角色')
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                TenantSelect::make()
                                    ->required(fn($context) => $context !== 'create' || request()->user()->hasRole('tenant_admin'))
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, $component) {
                                        // 當 tenant_id 變更時，清空目前選擇的角色，確保只能選擇新租戶的角色
                                        $form = $component->getParentComponent();
                                        if ($form && $rolesComponent = $form->getComponent('roles')) {
                                            $rolesComponent->state(null);
                                        }
                                    }),
                                Forms\Components\Select::make('roles')
                                    ->multiple()
                                    ->relationship('roles', 'name', function ($query, $record) {
                                        // Filament 5.8 使用簡化的邏輯，優先使用記錄本身的tenant_id
                                        $currentUser = auth()->user();
                                        $userTenantId = $record ? $record->tenant_id : $currentUser->tenant_id;

                                        if ($userTenantId) {
                                            // 只顯示屬於該User所屬Tenant的角色，排除super_admin
                                            return $query->where('roles.team_id', $userTenantId)
                                                ->where('name', '!=', 'super_admin');
                                        }
                                        // 編輯或創建Super Admin（tenant_id=null）時，只顯示全域的super_admin
                                        return $query->where('roles.team_id', 0)
                                            ->where('name', 'super_admin');
                                    })
                                    ->preload()
                                    ->searchable()
                                    ->placeholder('請選擇使用者的系統角色')
                                    ->helperText('只能選擇所屬租戶的有效角色，Super Admin 僅限全域使用者')
                                    ->required()
                                    ->reactive()
                                    ->afterStateUpdated(fn($state, $component) => $component->validate())
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('帳號安全')
                    ->description('設定使用者登入密碼')
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                Forms\Components\TextInput::make('password')
                                    ->password()
                                    ->label('密碼')
                                    ->hint('編輯模式：留空以保留目前的密碼')
                                    ->dehydrated(fn($state) => filled($state))
                                    ->afterStateHydrated(function ($component, $state) {
                                        // 永遠不把現有密碼載入到表單，確保安全性
                                    })
                                    ->required(fn($context) => $context === 'create')
                                    ->nullable(fn($context) => $context === 'edit'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
