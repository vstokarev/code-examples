<?php

namespace app\models\db;

use app\models\AuthUser;
use Yii;

/**
 * This is the model class for table "km_films_comments".
 *
 * @property int $id
 * @property string $date_added
 * @property int $user_id
 * @property int $film_id
 * @property string $comment
 * @property string $hash
 * @property string $ipaddress
 *
 * @property AuthUser $user
 */
class FilmComment extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'km_films_comments';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['date_added', 'user_id', 'film_id', 'comment'], 'required'],
            [['date_added'], 'safe'],
            [['user_id', 'film_id'], 'integer'],
            [['comment'], 'string'],
            [['hash'], 'string', 'max' => 32],
            [['ipaddress'], 'string', 'max' => 15],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'date_added' => 'Date Added',
            'user_id' => 'User ID',
            'film_id' => 'Film ID',
            'comment' => 'Comment',
            'hash' => 'Hash',
            'ipaddress' => 'Ipaddress',
        ];
    }

	/**
	 * @return \yii\db\ActiveQuery
	 */
    public function getUser()
    {
    	return $this->hasOne(AuthUser::class, ['id' => 'user_id']);
    }
}
