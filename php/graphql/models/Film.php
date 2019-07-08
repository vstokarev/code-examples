<?php

namespace app\models;

use app\models\db\FilmComment;
use app\queries\FilmQuery;
use Yii;

/**
 * This is the model class for table "{{%films}}".
 *
 * @property int $id
 * @property string $created
 * @property string $kind
 * @property string $name_rus
 * @property string $name_orig
 * @property string $let_rus
 * @property string $let_lat
 * @property string $poster
 * @property string $poster_v
 * @property string $poster_l
 * @property string $premier_rus
 * @property string $directors
 * @property string $actors
 * @property string $countries
 * @property int $timeline
 * @property string $agecategory
 * @property int $genre1
 * @property int $genre2
 * @property int $genre3
 * @property int $genre4
 * @property int $genre5
 * @property string $sticker
 * @property string $status
 * @property string $status_change
 * @property string $trailer_id
 * @property int $poisk_id
 * @property string $poisk_rating
 * @property int $weight
 * @property string $plot
 * @property string $film_date
 *
 * @property FilmsLinks[] $filmsLinks
 * @property Session[] $session
 */
class Film extends \yii\db\ActiveRecord
{
	/**
	 * @var int
	 */
	public $sessionsCount;

	/**
	 * @var
	 */
	public $format;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%films}}';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['created', 'name_rus', 'premier_rus', 'directors', 'actors', 'countries'], 'required'],
            [['created', 'premier_rus', 'status_change', 'film_date'], 'safe'],
            [['kind', 'agecategory', 'status', 'plot'], 'string'],
            [['timeline', 'genre1', 'genre2', 'genre3', 'genre4', 'genre5', 'poisk_id', 'weight'], 'integer'],
            [['name_rus', 'name_orig', 'actors'], 'string', 'max' => 255],
            [['let_rus', 'let_lat'], 'string', 'max' => 1],
            [['poster', 'poster_v', 'poster_l', 'sticker'], 'string', 'max' => 32],
            [['directors', 'countries'], 'string', 'max' => 128],
            [['trailer_id'], 'string', 'max' => 24],
            [['poisk_rating'], 'string', 'max' => 4],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'created' => 'Created',
            'kind' => 'Kind',
            'name_rus' => 'Name Rus',
            'name_orig' => 'Name Orig',
            'let_rus' => 'Let Rus',
            'let_lat' => 'Let Lat',
            'poster' => 'Poster',
            'poster_v' => 'Poster V',
            'poster_l' => 'Poster L',
            'premier_rus' => 'Premier Rus',
            'directors' => 'Directors',
            'actors' => 'Actors',
            'countries' => 'Countries',
            'timeline' => 'Timeline',
            'agecategory' => 'Agecategory',
            'genre1' => 'Genre1',
            'genre2' => 'Genre2',
            'genre3' => 'Genre3',
            'genre4' => 'Genre4',
            'genre5' => 'Genre5',
            'sticker' => 'Sticker',
            'status' => 'Status',
            'status_change' => 'Status Change',
            'trailer_id' => 'Trailer ID',
            'poisk_id' => 'Poisk ID',
            'poisk_rating' => 'Poisk Rating',
            'weight' => 'Weight',
            'plot' => 'Plot',
            'film_date' => 'Film Date',
        ];
    }

	/**
	 * @return FilmQuery
	 */
    public static function find()
    {
    	return new FilmQuery(get_called_class());
    }

	/**
	 * @return \yii\db\ActiveQuery
	 */
    public function getSessions()
    {
    	return $this->hasMany(Session::class, ['film_id' => 'id']);
    }

    /*public function __isset($name)
    {
    	if (parent::__isset($name)) {
    		return true;
	    }

    	$name = strtolower(preg_replace('/([A-Z])/', '_$0', $name));

	    return parent::__isset($name);
    }

    public function __get($name)
    {
    	return parent::__get($this->{strtolower(preg_replace('/([A-Z])/', '_$0', $name))});
    }*/

	/**
     * @return \yii\db\ActiveQuery
     */
    /*public function getFilmsLinks()
    {
        return $this->hasMany(FilmsLinks::className(), ['site_film_id' => 'id']);
    }*/

    /**
     * @return \yii\db\ActiveQuery
     */
    /*public function get()
    {
        return $this->hasMany(Schedule::className(), ['film_id' => 'id']);
    }*/

	/**
	 * @return \yii\db\ActiveQuery
	 */
    public function getComments()
    {
    	return $this->hasMany(FilmComment::class, ['film_id' => 'id']);
    }

	/**
	 * @return int event duration hours
	 */
    public function getDurationHours()
    {
    	return intdiv($this->timeline, 60);
    }

	/**
	 * @return int event duration minutes (excluding hours)
	 */
    public function getDurationMinutes(): int
    {
    	return $this->timeline - 60 * $this->getDurationHours();
    }
}
