import { useState, useEffect, useCallback } from 'react';
import {
  Tag, Plus, Edit, Trash2, Loader2, FileText, ChevronRight,
  X, ExternalLink, ArrowLeft, Sparkles
} from 'lucide-react';
import api from '../api';
import { AVAILABLE_COLORS } from '../constants';
import type { Tag as TagType, Paper } from '../types';

interface TagFormData {
  name: string;
  color: string;
  description: string;
}

const initialFormData: TagFormData = {
  name: '',
  color: 'bg-gray-500',
  description: '',
};

export default function TagManagement(): JSX.Element {
  const [tags, setTags] = useState<TagType[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [editingTag, setEditingTag] = useState<TagType | null>(null);
  const [formData, setFormData] = useState<TagFormData>(initialFormData);
  const [saving, setSaving] = useState(false);

  // タグ詳細表示用
  const [selectedTag, setSelectedTag] = useState<TagType | null>(null);
  const [tagPapers, setTagPapers] = useState<Paper[]>([]);
  const [loadingPapers, setLoadingPapers] = useState(false);

  // タグ一覧を取得
  const fetchTags = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      const data = await api.tags.list();
      if (data.success) {
        setTags(data.tags);
      }
    } catch (error) {
      console.error('Failed to fetch tags:', error);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchTags();
  }, [fetchTags]);

  // タグの論文一覧を取得
  const fetchTagPapers = async (tag: TagType): Promise<void> => {
    setSelectedTag(tag);
    setLoadingPapers(true);
    try {
      const data = await api.tags.papers(tag.id);
      if (data.success) {
        setTagPapers(data.papers);
        // タグ情報も更新（descriptionなど）
        setSelectedTag(data.tag);
      }
    } catch (error) {
      console.error('Failed to fetch tag papers:', error);
    } finally {
      setLoadingPapers(false);
    }
  };

  // モーダルを開く
  const openModal = (tag?: TagType): void => {
    if (tag) {
      setEditingTag(tag);
      setFormData({
        name: tag.name,
        color: tag.color,
        description: tag.description || '',
      });
    } else {
      setEditingTag(null);
      setFormData(initialFormData);
    }
    setShowModal(true);
  };

  // タグ保存
  const handleSave = async (): Promise<void> => {
    if (!formData.name.trim()) return;

    setSaving(true);
    try {
      if (editingTag) {
        await api.tags.update(editingTag.id, {
          name: formData.name,
          color: formData.color,
          description: formData.description || undefined,
        });
      } else {
        await api.tags.create(formData.name, formData.color);
        // 新規作成後にdescriptionを更新
        const newTagsData = await api.tags.list();
        const newTag = newTagsData.tags.find(t => t.name === formData.name);
        if (newTag && formData.description) {
          await api.tags.update(newTag.id, { description: formData.description });
        }
      }
      setShowModal(false);
      fetchTags();
    } catch (error) {
      alert('保存に失敗しました: ' + (error as Error).message);
    } finally {
      setSaving(false);
    }
  };

  // タグ削除
  const handleDelete = async (tag: TagType): Promise<void> => {
    if (!confirm(`「${tag.name}」を削除しますか？このTagが付いた論文からも削除されます．`)) return;

    try {
      await api.tags.delete(tag.id);
      fetchTags();
      if (selectedTag?.id === tag.id) {
        setSelectedTag(null);
        setTagPapers([]);
      }
    } catch (error) {
      alert('削除に失敗しました: ' + (error as Error).message);
    }
  };

  // 日付フォーマット
  const formatDate = (dateString: string | null): string => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日`;
  };

  // タグ一覧表示
  if (!selectedTag) {
    return (
      <main className="max-w-6xl mx-auto px-4 py-6">
        {/* ヘッダー */}
        <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-2 text-sm text-gray-600">
            <Tag className="w-4 h-4" />
            {tags.length}件のTag
          </div>
          <button
            onClick={() => openModal()}
            className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors"
          >
            <Plus className="w-4 h-4" />
            新しいTagを作成
          </button>
        </div>

        {/* タグ一覧 */}
        {loading ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className="w-8 h-8 animate-spin text-indigo-600" />
          </div>
        ) : tags.length === 0 ? (
          <div className="text-center py-16">
            <Tag className="w-16 h-16 text-gray-300 mx-auto mb-4" />
            <p className="text-gray-500 text-lg">Tagがありません</p>
            <p className="text-gray-400 text-sm mt-2">
              論文にTagを付けて整理しましょう
            </p>
          </div>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {tags.map((tag) => (
              <div
                key={tag.id}
                className="bg-white rounded-xl shadow-sm border border-gray-200 p-4 hover:shadow-md transition-all cursor-pointer group"
                onClick={() => fetchTagPapers(tag)}
              >
                <div className="flex items-start gap-3">
                  <div className={`w-3 h-3 rounded-full mt-1.5 flex-shrink-0 ${tag.color}`} />
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <h3 className="font-semibold text-gray-900 truncate">{tag.name}</h3>
                      <ChevronRight className="w-4 h-4 text-gray-400 opacity-0 group-hover:opacity-100 transition-opacity" />
                    </div>
                    {tag.description && (
                      <p className="text-sm text-gray-500 mt-1 line-clamp-2">{tag.description}</p>
                    )}
                    <div className="flex items-center gap-2 mt-2 text-xs text-gray-500">
                      <FileText className="w-3 h-3" />
                      {tag.paper_count || 0}件の論文
                    </div>
                  </div>
                  <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        openModal(tag);
                      }}
                      className="p-1.5 text-gray-500 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                      title="編集"
                    >
                      <Edit className="w-4 h-4" />
                    </button>
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        handleDelete(tag);
                      }}
                      className="p-1.5 text-gray-500 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                      title="削除"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}

        {/* タグ作成/編集モーダル */}
        {showModal && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl shadow-xl max-w-md w-full p-6">
              <div className="flex items-center justify-between mb-4">
                <h2 className="text-lg font-semibold">
                  {editingTag ? 'Tagを編集' : '新しいTagを作成'}
                </h2>
                <button
                  onClick={() => setShowModal(false)}
                  className="p-1 text-gray-400 hover:text-gray-600 rounded"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Tag名 <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="例: 機械学習"
                  />
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    色
                  </label>
                  <div className="flex flex-wrap gap-2">
                    {AVAILABLE_COLORS.map((color) => (
                      <button
                        key={color.id}
                        onClick={() => setFormData({ ...formData, color: color.id })}
                        className={`w-8 h-8 rounded-full ${color.id} ${
                          formData.color === color.id
                            ? 'ring-2 ring-offset-2 ring-indigo-500'
                            : ''
                        }`}
                        title={color.name}
                      />
                    ))}
                  </div>
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    説明（任意）
                  </label>
                  <textarea
                    value={formData.description}
                    onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 resize-none"
                    rows={3}
                    placeholder="このTagの説明を入力..."
                  />
                </div>
              </div>

              <div className="flex justify-end gap-3 mt-6">
                <button
                  onClick={() => setShowModal(false)}
                  className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
                >
                  キャンセル
                </button>
                <button
                  onClick={handleSave}
                  disabled={!formData.name.trim() || saving}
                  className="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
                >
                  {saving && <Loader2 className="w-4 h-4 animate-spin" />}
                  {editingTag ? '更新' : '作成'}
                </button>
              </div>
            </div>
          </div>
        )}
      </main>
    );
  }

  // タグ詳細（論文一覧）表示
  return (
    <main className="max-w-6xl mx-auto px-4 py-6">
      {/* ヘッダー */}
      <div className="mb-6">
        <button
          onClick={() => {
            setSelectedTag(null);
            setTagPapers([]);
          }}
          className="flex items-center gap-2 text-gray-600 hover:text-indigo-600 mb-4 transition-colors"
        >
          <ArrowLeft className="w-4 h-4" />
          Tagグループ一覧に戻る
        </button>

        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
          <div className="flex items-start gap-4">
            <div className={`w-4 h-16 rounded-full ${selectedTag.color}`} />
            <div className="flex-1">
              <div className="flex items-center gap-3 flex-wrap">
                <h1 className="text-2xl font-bold text-gray-900">{selectedTag.name}</h1>
                <span className="text-sm text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">
                  {tagPapers.length}件の論文
                </span>
              </div>
              {selectedTag.description && (
                <p className="text-gray-600 mt-2">{selectedTag.description}</p>
              )}
            </div>
            <div className="flex items-center gap-2">
              <button
                onClick={() => openModal(selectedTag)}
                className="p-2 text-gray-600 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                title="編集"
              >
                <Edit className="w-5 h-5" />
              </button>
              <button
                onClick={() => handleDelete(selectedTag)}
                className="p-2 text-gray-600 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                title="削除"
              >
                <Trash2 className="w-5 h-5" />
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* 論文一覧 */}
      {loadingPapers ? (
        <div className="flex items-center justify-center py-12">
          <Loader2 className="w-8 h-8 animate-spin text-indigo-600" />
        </div>
      ) : tagPapers.length === 0 ? (
        <div className="text-center py-16">
          <FileText className="w-16 h-16 text-gray-300 mx-auto mb-4" />
          <p className="text-gray-500 text-lg">このTagが付いた論文はありません</p>
        </div>
      ) : (
        <div className="space-y-4">
          {tagPapers.map((paper) => (
            <div
              key={paper.id}
              className="bg-white rounded-xl shadow-sm border border-gray-200 p-5 hover:shadow-md transition-all"
            >
              <div className="flex items-start gap-4">
                <div className={`w-1.5 self-stretch rounded-full ${paper.journal_color}`} />
                <div className="flex-1 min-w-0">
                  {/* メタ情報 */}
                  <div className="flex items-center gap-2 mb-2 flex-wrap">
                    <span className={`px-2.5 py-1 text-xs font-medium text-white rounded-lg ${paper.journal_color}`}>
                      {paper.journal_name}
                    </span>
                    <span className="text-xs text-gray-500">
                      {formatDate(paper.published_date)}
                    </span>
                    {paper.has_summary && (
                      <span className="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full flex items-center gap-1">
                        <Sparkles className="w-3 h-3" />
                        要約済
                      </span>
                    )}
                  </div>

                  {/* タイトル */}
                  <h3 className="text-lg font-semibold text-gray-900 mb-2">{paper.title}</h3>

                  {/* 著者 */}
                  <p className="text-sm text-gray-600 mb-3">
                    {Array.isArray(paper.authors) ? paper.authors.join(', ') : paper.authors}
                  </p>

                  {/* 他のタグ */}
                  {paper.tags && paper.tags.length > 0 && (
                    <div className="flex items-center gap-1.5 flex-wrap mb-3">
                      {paper.tags.map((t) => (
                        <span
                          key={t.id}
                          className={`inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded-full ${t.color} text-white`}
                        >
                          <Tag className="w-3 h-3" />
                          {t.name}
                        </span>
                      ))}
                    </div>
                  )}

                  {/* アブストラクト */}
                  {paper.abstract && (
                    <p className="text-sm text-gray-700 line-clamp-3 mb-3">{paper.abstract}</p>
                  )}

                  {/* リンク */}
                  {paper.url && (
                    <a
                      href={paper.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
                    >
                      <ExternalLink className="w-4 h-4" />
                      論文を開く
                    </a>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* タグ編集モーダル（詳細画面用） */}
      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl shadow-xl max-w-md w-full p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold">Tagを編集</h2>
              <button
                onClick={() => setShowModal(false)}
                className="p-1 text-gray-400 hover:text-gray-600 rounded"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Tag名 <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">色</label>
                <div className="flex flex-wrap gap-2">
                  {AVAILABLE_COLORS.map((color) => (
                    <button
                      key={color.id}
                      onClick={() => setFormData({ ...formData, color: color.id })}
                      className={`w-8 h-8 rounded-full ${color.id} ${
                        formData.color === color.id
                          ? 'ring-2 ring-offset-2 ring-indigo-500'
                          : ''
                      }`}
                      title={color.name}
                    />
                  ))}
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  説明（任意）
                </label>
                <textarea
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 resize-none"
                  rows={3}
                  placeholder="このタグの説明を入力..."
                />
              </div>
            </div>

            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => setShowModal(false)}
                className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
              >
                キャンセル
              </button>
              <button
                onClick={async () => {
                  await handleSave();
                  // 更新後にタグ情報を再取得
                  if (selectedTag) {
                    fetchTagPapers({ ...selectedTag, ...formData });
                  }
                }}
                disabled={!formData.name.trim() || saving}
                className="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
              >
                {saving && <Loader2 className="w-4 h-4 animate-spin" />}
                更新
              </button>
            </div>
          </div>
        </div>
      )}
    </main>
  );
}
