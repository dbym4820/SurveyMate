#!/usr/bin/env node
/**
 * ユーザー作成スクリプト
 * 使用方法: npx tsx src/scripts/create-user.ts <username> <password> [--admin]
 */

import 'dotenv/config';
import readline from 'readline';
import * as auth from '../lib/auth.js';
import * as db from '../lib/database.js';

async function createUser(): Promise<void> {
  const args = process.argv.slice(2);

  let username = args[0];
  let password = args[1];
  const isAdmin = args.includes('--admin');

  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout,
  });

  const question = (prompt: string): Promise<string> => new Promise((resolve) => {
    rl.question(prompt, resolve);
  });

  try {
    if (!username) {
      username = await question('ユーザー名: ');
    }

    if (!password) {
      password = await question('パスワード: ');
    }

    if (password.length < 8) {
      console.error('エラー: パスワードは8文字以上必要です');
      process.exit(1);
    }

    const email = await question('メールアドレス（任意）: ');

    console.log('\n作成するユーザー:');
    console.log(`  ユーザー名: ${username}`);
    console.log(`  メール: ${email || '(なし)'}`);
    console.log(`  管理者: ${isAdmin ? 'はい' : 'いいえ'}`);

    const confirm = await question('\n作成しますか？ (y/N): ');

    if (confirm.toLowerCase() !== 'y') {
      console.log('キャンセルしました');
      process.exit(0);
    }

    const user = await auth.registerUser(username, password, email || null, isAdmin);

    console.log('\nユーザーを作成しました:');
    console.log(`  ID: ${user.id}`);
    console.log(`  ユーザー名: ${user.username}`);

  } catch (error) {
    console.error('エラー:', (error as Error).message);
    process.exit(1);
  } finally {
    rl.close();
    await db.end();
  }
}

createUser();
